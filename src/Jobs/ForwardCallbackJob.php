<?php

declare(strict_types=1);

namespace Aviagram\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts the normalised callback payload to the merchant app's callback URL.
 * Dispatched asynchronously so the 201 response to Aviagram is immediate.
 *
 * Queue configuration (tries, backoff) is intentionally declared on the class so
 * the host application's queue worker picks them up without extra config.
 */
class ForwardCallbackJob implements ShouldQueue
{
    /** @var int Maximum queue attempts before the job is marked failed. */
    public int $tries = 5;

    private const FORWARD_TIMEOUT_SECONDS = 30;
    private const TRANSACTIONS_TABLE = 'aviagram_transactions';

    /**
     * @param array<string, mixed> $normalizedPayload
     */
    public function __construct(
        private readonly string $orderId,
        private readonly string $callbackUrl,
        private readonly array $normalizedPayload,
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
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(self::FORWARD_TIMEOUT_SECONDS)
                ->post($this->callbackUrl, $this->normalizedPayload);

            DB::table(self::TRANSACTIONS_TABLE)
                ->where('order_id', $this->orderId)
                ->update([
                    'forwarded' => $response->successful(),
                    'forward_status' => $response->status(),
                    'forward_error' => null,
                    'forwarded_at' => $response->successful() ? Carbon::now() : null,
                    'updated_at' => Carbon::now(),
                ]);
        } catch (Throwable $exception) {
            DB::table(self::TRANSACTIONS_TABLE)
                ->where('order_id', $this->orderId)
                ->update([
                    'forwarded' => false,
                    'forward_error' => $exception->getMessage(),
                    'updated_at' => Carbon::now(),
                ]);

            throw $exception;
        }
    }
}
