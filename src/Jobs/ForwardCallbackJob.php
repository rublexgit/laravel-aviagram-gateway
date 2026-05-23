<?php

declare(strict_types=1);

namespace Aviagram\Jobs;

use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rublex\CoreGateway\Data\CallbackForwardResultData;
use Rublex\CoreGateway\Data\PaymentOutcomeData;
use Throwable;

/**
 * Posts the normalised callback payload to the merchant app's callback URL.
 * Dispatched asynchronously so the 201 response to Aviagram is immediate.
 *
 * Persisted forward outcome follows the CallbackForwardResultData contract:
 * forwarded             ← success
 * forward_status        ← httpStatus
 * forward_error         ← errorMessage
 * forward_response_body ← responseBody
 * forwarded_at          ← respondedAt (only when success is true)
 */
class ForwardCallbackJob implements ShouldQueue
{
    /** @var int Maximum queue attempts before the job is marked failed. */
    public int $tries = 5;

    private const FORWARD_TIMEOUT_SECONDS = 30;
    private const TRANSACTIONS_TABLE = 'aviagram_transactions';

    public function __construct(
        private readonly string $orderId,
        private readonly string $callbackUrl,
        private readonly PaymentOutcomeData $outcome,
    ) {}

    /**
     * Delay in seconds between successive retry attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(): void
    {
        try {
            $result = $this->sendForwardRequest();
        } catch (Throwable $exception) {
            Log::channel('fiat-gateway')->error('aviagram.forward.exception', [
                'gateway'      => 'aviagram',
                'order_id'     => $this->orderId,
                'callback_url' => $this->callbackUrl,
                'attempt'      => method_exists($this, 'attempts') ? $this->attempts() : null,
                'exception'    => $exception::class,
                'message'      => $exception->getMessage(),
            ]);
            $this->persistForwardResult(CallbackForwardResultData::fromException($exception));
            throw $exception;
        }

        $this->persistForwardResult($result);
    }

    /**
     * Executes the HTTP POST to the merchant callback URL and wraps the outcome
     * in a CallbackForwardResultData. Extracted as a protected method so tests
     * can substitute it without booting the HTTP facade.
     */
    protected function sendForwardRequest(): CallbackForwardResultData
    {
        $body = $this->outcome->toArray();

        Log::channel('fiat-gateway')->info('aviagram.forward.request', [
            'gateway'      => 'aviagram',
            'order_id'     => $this->orderId,
            'attempt'      => method_exists($this, 'attempts') ? $this->attempts() : null,
            'method'       => 'POST',
            'callback_url' => $this->callbackUrl,
            'headers'      => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body'         => $body,
        ]);

        $startedAt = microtime(true);

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(self::FORWARD_TIMEOUT_SECONDS)
            ->post($this->callbackUrl, $body);

        $rawBody = (string) $response->body();

        Log::channel('fiat-gateway')->info('aviagram.forward.response', [
            'gateway'          => 'aviagram',
            'order_id'         => $this->orderId,
            'callback_url'     => $this->callbackUrl,
            'duration_ms'      => (int) ((microtime(true) - $startedAt) * 1000),
            'status_code'      => $response->status(),
            'successful'       => $response->successful(),
            'response_headers' => $response->headers(),
            'raw_body'         => $rawBody,
        ]);

        return CallbackForwardResultData::fromHttpResponse(
            successful: $response->successful(),
            httpStatus: $response->status(),
            responseBody: $rawBody !== '' ? $rawBody : null,
            respondedAt: new DateTimeImmutable(),
        );
    }

    private function persistForwardResult(CallbackForwardResultData $result): void
    {
        DB::table(self::TRANSACTIONS_TABLE)
            ->where('order_id', $this->orderId)
            ->update([
                'forwarded'             => $result->success(),
                'forward_status'        => $result->httpStatus(),
                'forward_error'         => $result->errorMessage(),
                'forward_response_body' => $result->responseBody(),
                'forwarded_at'          => $result->success() && $result->respondedAt() !== null
                    ? Carbon::instance($result->respondedAt())
                    : null,
                'updated_at'            => Carbon::now(),
            ]);
    }
}
