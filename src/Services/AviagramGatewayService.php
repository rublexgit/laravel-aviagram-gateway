<?php

declare(strict_types=1);

namespace Aviagram\Services;

use Aviagram\Data\OrderData;
use Rublex\CoreGateway\Contracts\Common\GatewayInterface;
use Rublex\CoreGateway\Contracts\Payment\InitiatesPaymentInterface;
use Rublex\CoreGateway\Data\DynamicDataBag;
use Rublex\CoreGateway\Data\PaymentInitResultData;
use Rublex\CoreGateway\Data\PaymentRequestData;
use Rublex\CoreGateway\Enums\GatewayType;
use Rublex\CoreGateway\Enums\PaymentStatus;
use Rublex\CoreGateway\Exceptions\ValidationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class AviagramGatewayService implements GatewayInterface, InitiatesPaymentInterface
{
    private const CREATE_FORM_PATH = '/api/payment/createForm';
    private const SUPPORTED_CURRENCY = 'EUR';

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

        $responsePayload = $this->sendCreateFormRequest($this->resolveOrderPayload($request));

        return $this->mapInitResponseToResult($responsePayload);
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
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function sendCreateFormRequest(array $payload): array
    {
        $response = Http::acceptJson()->asJson()->timeout(30)
            ->withHeaders([
                'Authorization' => $this->authorizationHeader()
            ])
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
        $statusValue = $this->extractString($response, ['status', 'paymentStatus']);
        if ($statusValue !== null) {
            return match (strtolower($statusValue)) {
                'success', 'paid', 'completed' => PaymentStatus::SUCCESS,
                'pending', 'processing', 'waiting' => PaymentStatus::PENDING,
                'failed', 'error' => PaymentStatus::FAILED,
                'canceled', 'cancelled' => PaymentStatus::CANCELED,
                'expired' => PaymentStatus::EXPIRED,
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
}
