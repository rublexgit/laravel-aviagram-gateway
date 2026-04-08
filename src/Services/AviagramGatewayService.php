<?php

declare(strict_types=1);

namespace Aviagram\Services;

use Aviagram\Data\OrderData;
use Rublex\CoreGateway\Contracts\Common\GatewayInterface;
use Rublex\CoreGateway\Contracts\Http\ConfiguresGatewayHttpInterface;
use Rublex\CoreGateway\Contracts\Payment\InitiatesPaymentInterface;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentInitResultData;
use Rublex\CoreGateway\Data\PaymentRequestData;
use Rublex\CoreGateway\Enums\GatewayType;
use Rublex\CoreGateway\Enums\PaymentStatus;
use Rublex\CoreGateway\Exceptions\ValidationException;
use Rublex\CoreGateway\Support\GatewayHttpOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class AviagramGatewayService implements GatewayInterface, InitiatesPaymentInterface, ConfiguresGatewayHttpInterface
{
    private const CREATE_FORM_PATH = '/api/payment/createForm';
    private const SUPPORTED_CURRENCY = 'EUR';
    private const TRANSACTIONS_TABLE = 'aviagram_transactions';

    public function initiatePayment(OrderData $order, string $userCallbackUrl): array
    {
        $request = new PaymentRequestData(
            gatewayCode: $this->code(),
            orderId: $order->getId(),
            amount: $order->amount(),
            currency: $order->currency(),
            callbackUrl: $userCallbackUrl,
            meta: new DynamicDataBag([
                'order' => $order->toArray(),
                'provider' => [
                    'requestedCurrency' => $order->currency(),
                ],
            ])
        );

        $result = $this->initiate($request);

        return [
            'status' => $result->status()->value,
            'responseCode' => $result->meta()->get('responseCode'),
            'responseMessage' => $result->meta()->get('responseMessage'),
            'orderId' => $order->getId(),
            'transactionId' => $result->transactionId(),
            'redirect_url' => $result->redirectUrl(),
            'gatewayReference' => $result->gatewayReference(),
            'raw' => $result->raw()->all(),
        ];
    }

    public function code(): string
    {
        return 'aviagram';
    }

    public function type(): GatewayType
    {
        return GatewayType::FIAT;
    }

    public function initiate(PaymentRequestData $request): PaymentInitResultData
    {
        if (strtoupper($request->currency()) !== self::SUPPORTED_CURRENCY) {
            throw new ValidationException('Only EUR currency is supported.');
        }

        $missingConfigKeys = $this->getMissingConfigKeys();
        if ($missingConfigKeys !== []) {
            return $this->mapInitResponseToResult([
                'responseCode' => '5000002',
                'responseMessage' => 'Aviagram configuration is missing.',
                'missingConfig' => $missingConfigKeys,
            ]);
        }

        // Generate a cryptographically random, single-use callback key.
        // Only the SHA-256 hash is stored; the raw token travels in the callback URL.
        $callbackKey = bin2hex(random_bytes(32));

        $this->storeUserCallbackUrl(
            $request->orderId(),
            $request->callbackUrl(),
            hash('sha256', $callbackKey),
            $request->amount(),
            $request->currency(),
        );

        $responsePayload = $this->sendCreateFormRequest(
            $this->resolveCreateFormPayload($request, $callbackKey)
        );
        $this->storeInitTransaction($request->orderId(), $responsePayload);

        return $this->mapInitResponseToResult($responsePayload);
    }

    public function gatewayHttpOptions(): array
    {
        return GatewayHttpOptions::fromConfig(
            (array) Config::get('aviagram.http', [])
        );
    }

    public function getUserCallbackUrlForOrder(string $orderId): ?string
    {
        $value = DB::table(self::TRANSACTIONS_TABLE)
            ->where('order_id', $orderId)
            ->value('callback_url');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function forgetUserCallbackUrlForOrder(string $orderId): void
    {
        DB::table(self::TRANSACTIONS_TABLE)
            ->where('order_id', $orderId)
            ->update([
                'callback_url' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Resolves the aviagram_transactions row by matching the SHA-256 hash of the
     * provided raw callback key. Returns null when no matching, unconsumed row exists.
     */
    public function resolveTransactionByCallbackKey(string $callbackKey): ?object
    {
        $row = DB::table(self::TRANSACTIONS_TABLE)
            ->where('callback_key_hash', hash('sha256', $callbackKey))
            ->first();

        return ($row instanceof \stdClass) ? $row : null;
    }

    /**
     * Marks the callback key for the given order as consumed so replay attempts
     * are rejected. Should be called only after a successful callback has been
     * acknowledged and the forward job dispatched.
     */
    public function markCallbackKeyConsumed(string $orderId): void
    {
        DB::table(self::TRANSACTIONS_TABLE)
            ->where('order_id', $orderId)
            ->update([
                'callback_key_consumed' => true,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Persists audit data for a callback attempt together with the validation
     * outcome. Called for every request that reaches the validation stage.
     *
     * @param array<string, mixed> $normalizedPayload
     * @param array<string, mixed> $headers
     */
    public function storeCallbackAudit(
        string $orderId,
        array $normalizedPayload,
        string $requestUrl,
        string $clientIp,
        array $headers,
        string $rawBody,
        bool $validationPassed,
        ?string $validationReason,
        bool $jobDispatched = false,
    ): void {
        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'status' => $this->normalizeNullableString($normalizedPayload['status'] ?? null),
                'response_code' => $this->normalizeNullableString($normalizedPayload['responseCode'] ?? null),
                'response_message' => $this->normalizeNullableString($normalizedPayload['responseMessage'] ?? null),
                'transaction_id' => $this->normalizeNullableString($normalizedPayload['transactionId'] ?? null),
                'gateway_reference' => $this->normalizeNullableString($normalizedPayload['gatewayReference'] ?? null),
                'callback_payload' => $this->encodeJsonPayload($normalizedPayload),
                'callback_request_url' => $requestUrl,
                'callback_client_ip' => $clientIp,
                'callback_headers' => $this->encodeJsonPayload($headers),
                'callback_raw_body' => $rawBody,
                'callback_validation_passed' => $validationPassed,
                'callback_validation_reason' => $validationReason,
                'forward_job_dispatched' => $jobDispatched,
                'forward_job_dispatched_at' => $jobDispatched ? $now : null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'status',
            'response_code',
            'response_message',
            'transaction_id',
            'gateway_reference',
            'callback_payload',
            'callback_request_url',
            'callback_client_ip',
            'callback_headers',
            'callback_raw_body',
            'callback_validation_passed',
            'callback_validation_reason',
            'forward_job_dispatched',
            'forward_job_dispatched_at',
            'updated_at',
        ]);
    }

    /**
     * @param array<string, mixed> $normalizedPayload
     */
    public function storeCallbackResult(
        string $orderId,
        array $normalizedPayload,
        bool $forwarded,
        ?int $forwardStatus,
        ?string $error = null
    ): void {
        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'status' => $this->normalizeNullableString($normalizedPayload['status'] ?? null),
                'response_code' => $this->normalizeNullableString($normalizedPayload['responseCode'] ?? null),
                'response_message' => $this->normalizeNullableString($normalizedPayload['responseMessage'] ?? null),
                'transaction_id' => $this->normalizeNullableString($normalizedPayload['transactionId'] ?? null),
                'gateway_reference' => $this->normalizeNullableString($normalizedPayload['gatewayReference'] ?? null),
                'callback_payload' => $this->encodeJsonPayload($normalizedPayload),
                'forwarded' => $forwarded,
                'forward_status' => $forwardStatus,
                'forward_error' => $this->normalizeNullableString($error),
                'forwarded_at' => $forwarded ? $now : null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'status',
            'response_code',
            'response_message',
            'transaction_id',
            'gateway_reference',
            'callback_payload',
            'forwarded',
            'forward_status',
            'forward_error',
            'forwarded_at',
            'updated_at',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     status: string,
     *     responseCode: string,
     *     responseMessage: string,
     *     orderId: string|null,
     *     transactionId: string|null,
     *     gatewayReference: string|null,
     *     amount: string|null,
     *     currency: string|null,
     *     raw: array<string, mixed>
     * }
     */
    public function normalizeCallbackPayload(array $payload): array
    {
        return [
            'status' => $this->resolveInitStatus($payload)->value,
            'responseCode' => $this->extractString($payload, ['responseCode']) ?? '2000000',
            'responseMessage' => $this->extractString($payload, ['responseMessage', 'message']) ?? 'Callback received.',
            'orderId' => $this->extractString($payload, ['order.id', 'orderId', 'invoiceNo']),
            'transactionId' => $this->extractString($payload, ['transactionId', 'orderId', 'id']),
            'gatewayReference' => $this->extractString($payload, ['gatewayReference', 'reference', 'invoiceNo']),
            'amount' => $this->extractString($payload, ['amount', 'order.amount']),
            'currency' => $this->extractString($payload, ['currency', 'order.currency']),
            'raw' => $payload,
        ];
    }

    private function authorizationHeader(): string
    {
        $clientId = trim((string) Config::get('aviagram.client_id'));
        $clientSecret = trim((string) Config::get('aviagram.client_secret'));

        return 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
    }

    private function buildCreateFormUrl(): string
    {
        $baseUrl = rtrim((string) Config::get('aviagram.base_url'), '/');

        return $baseUrl . self::CREATE_FORM_PATH;
    }

    private function getMissingConfigKeys(): array
    {
        $requiredConfig = [
            'AVIAGRAM_BASE_URL' => Config::get('aviagram.base_url'),
            'AVIAGRAM_CLIENT_ID' => Config::get('aviagram.client_id'),
            'AVIAGRAM_CLIENT_SECRET' => Config::get('aviagram.client_secret'),
        ];

        $missingKeys = [];
        foreach ($requiredConfig as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                $missingKeys[] = $key;
            }
        }

        return $missingKeys;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveOrderPayload(PaymentRequestData $request): array
    {
        $orderOverrides = $request->meta()->get('order', []);
        if (!is_array($orderOverrides)) {
            $orderOverrides = [];
        }

        return array_replace([
            'amount' => $request->amount(),
            'currency' => strtolower($request->currency()) . '-sp',
        ], $orderOverrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveCreateFormPayload(PaymentRequestData $request, string $callbackKey): array
    {
        return array_replace(
            $this->resolveOrderPayload($request),
            ['callbackUrl' => $this->resolveGatewayCallbackUrl($callbackKey)]
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function sendCreateFormRequest(array $payload): array
    {
        $response = Http::acceptJson()->asJson()
            ->withHeaders([
                'Authorization' => $this->authorizationHeader(),
            ])
            ->withOptions($this->gatewayHttpOptions())
            ->post($this->buildCreateFormUrl(), $payload);

        $decoded = $response->json();
        if (!is_array($decoded)) {
            return [
                'responseCode' => (string) $response->status(),
                'responseMessage' => 'Invalid JSON response from Aviagram.',
            ];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function mapInitResponseToResult(array $response): PaymentInitResultData
    {
        return new PaymentInitResultData(
            status: $this->resolveInitStatus($response),
            transactionId: $this->extractString($response, ['transactionId', 'orderId', 'id']),
            redirectUrl: $this->extractString($response, ['redirectUrl', 'redirect_url', 'url', 'formUrl']),
            gatewayReference: $this->extractString($response, ['gatewayReference', 'reference', 'invoiceNo']),
            meta: new DynamicDataBag([
                'responseCode' => $this->extractString($response, ['responseCode']),
                'responseMessage' => $this->extractString($response, ['responseMessage']),
            ]),
            raw: new DynamicDataBag($response)
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function resolveInitStatus(array $response): PaymentStatus
    {
        $statusValue = $this->extractString($response, ['status']);
        if ($statusValue !== null) {
            return match (strtolower($statusValue)) {
                'RECEIVED' => PaymentStatus::SUCCESS,
                'CANCELED'=> PaymentStatus::CANCELED,
                default => PaymentStatus::UNKNOWN,
            };
        }

        $responseCode = $this->extractString($response, ['responseCode']);
        if ($responseCode === null) {
            return PaymentStatus::UNKNOWN;
        }

        return str_starts_with($responseCode, '2') ? PaymentStatus::PENDING : PaymentStatus::FAILED;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<int, string> $paths
     */
    private function extractString(array $response, array $paths): ?string
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $cursor = $response;

            foreach ($segments as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    continue 2;
                }

                $cursor = $cursor[$segment];
            }

            if (is_string($cursor) && trim($cursor) !== '') {
                return $cursor;
            }
        }

        return null;
    }

    private function resolveGatewayCallbackUrl(string $callbackKey): string
    {
        return URL::route('aviagram.callback', ['callbackKey' => $callbackKey]);
    }

    private function storeUserCallbackUrl(
        string $orderId,
        string $userCallbackUrl,
        string $callbackKeyHash,
        string $expectedAmount,
        string $expectedCurrency,
    ): void {
        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'callback_url' => $userCallbackUrl,
                'callback_key_hash' => $callbackKeyHash,
                'callback_key_consumed' => false,
                'expected_amount' => $expectedAmount,
                'expected_currency' => strtoupper($expectedCurrency),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'callback_url',
            'callback_key_hash',
            'callback_key_consumed',
            'expected_amount',
            'expected_currency',
            'updated_at',
        ]);
    }

    /**
     * @param array<string, mixed> $responsePayload
     */
    private function storeInitTransaction(string $orderId, array $responsePayload): void
    {
        $now = Carbon::now();
        DB::table(self::TRANSACTIONS_TABLE)->upsert([
            [
                'order_id' => $orderId,
                'status' => $this->resolveInitStatus($responsePayload)->value,
                'response_code' => $this->extractString($responsePayload, ['responseCode']),
                'response_message' => $this->extractString($responsePayload, ['responseMessage']),
                'transaction_id' => $this->extractString($responsePayload, ['transactionId', 'orderId', 'id']),
                'gateway_reference' => $this->extractString($responsePayload, ['gatewayReference', 'reference', 'invoiceNo']),
                'provider_payload' => $this->encodeJsonPayload($responsePayload),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['order_id'], [
            'status',
            'response_code',
            'response_message',
            'transaction_id',
            'gateway_reference',
            'provider_payload',
            'updated_at',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJsonPayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
