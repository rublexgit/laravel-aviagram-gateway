<?php

declare(strict_types=1);

namespace Aviagram\Http\Controllers;

use Aviagram\Jobs\ForwardCallbackJob;
use Aviagram\Services\AviagramGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Rublex\CoreGateway\Contracts\Common\GatewayCredentialResolverInterface;
use Rublex\CoreGateway\Exceptions\GatewayCredentialException;
use Rublex\CoreGateway\Support\CallbackCredentialResolver;

/**
 * Handles inbound payment callbacks from Aviagram.
 *
 * Route: POST api/v1/aviagram/callback/{callbackKey}
 *
 * The {callbackKey} path segment is a cryptographically random, single-use token
 * that was generated at payment-init time and embedded in the callback URL
 * registered with Aviagram. Its SHA-256 hash is stored on the transaction row;
 * the raw token is never logged or stored.
 *
 * Success response: HTTP 201
 * {
 *   "responseCode": "2010000",
 *   "responseMessage": "Callback accepted."
 * }
 */
class CallbackController
{
    /** @var list<string> Headers redacted from the stored audit record. */
    private const MASKED_HEADERS = ['authorization', 'x-api-key', 'x-auth-token', 'cookie'];

    public function handle(
        Request $request,
        string $callbackKey,
        GatewayCredentialResolverInterface $credentialResolver,
    ): JsonResponse {
        // Capture audit data before touching the body stream or returning early.
        $rawBody = $request->getContent();
        $fullUrl = $request->fullUrl();
        $clientIp = (string) $request->ip();
        $headers = $this->sanitizeHeaders($request->headers->all());

        // First action: dump the raw inbound callback to the fiat-gateway log
        // before any DB lookup or validation. Captures everything the gateway
        // sent us so we can debug even if validation later rejects the request.
        Log::channel('fiat-gateway')->info('aviagram.callback.received', [
            'gateway'           => 'aviagram',
            'method'            => $request->getMethod(),
            'url'               => $fullUrl,
            'client_ip'         => $clientIp,
            'callback_key_hash' => hash('sha256', $callbackKey),
            'query'             => $request->query->all(),
            'headers'           => $request->headers->all(),
            'raw_body'          => $rawBody,
        ]);

        // Resolve the transaction by hashing the incoming key and doing a DB lookup.
        // No raw key ever reaches a log or the database.
        $transaction = AviagramGatewayService::findTransactionByCallbackKey($callbackKey);
        if ($transaction === null) {
            return $this->respond(null, [
                'responseCode' => '4040002',
                'responseMessage' => 'Invalid callback key.',
            ], 404, 'invalid_callback_key');
        }

        $orderId = (string) $transaction->order_id;

        // Rebuild the account that opened this transaction; several accounts of
        // this driver share the callback route.
        try {
            $aviagramGatewayService = AviagramGatewayService::fromCredentials(CallbackCredentialResolver::forTransaction(
                $credentialResolver,
                AviagramGatewayService::driver(),
                $transaction->fiat_gateway_id ?? null,
            ));
        } catch (GatewayCredentialException $e) {
            Log::channel('fiat-gateway')->error('aviagram.callback.credentials_unavailable', [
                'gateway'         => 'aviagram',
                'order_id'        => $orderId,
                'fiat_gateway_id' => $transaction->fiat_gateway_id ?? null,
                'message'         => $e->getMessage(),
            ]);

            return $this->respond($orderId, [
                'responseCode' => '5030001',
                'responseMessage' => 'Gateway credentials unavailable.',
            ], 503, 'credentials_unavailable');
        }

        // Reject replay attempts — key already consumed by a previous successful callback.
        if ((bool) $transaction->callback_key_consumed) {
            return $this->respond($orderId, [
                'responseCode' => '4030001',
                'responseMessage' => 'Callback key has already been used.',
            ], 403, 'callback_key_already_consumed');
        }

        // Parse JSON body.
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $aviagramGatewayService->storeCallbackAudit(
                $orderId, [], $fullUrl, $clientIp, $headers, $rawBody,
                false, 'Invalid JSON body.',
            );
            return $this->respond($orderId, [
                'responseCode' => '4000002',
                'responseMessage' => 'Invalid JSON body.',
            ], 400, 'invalid_json_body');
        }

        $normalizedPayload = $aviagramGatewayService->normalizeCallbackPayload($payload);

        // Validate invoice / order ID.
        $invoiceId = $normalizedPayload['orderId'];
        if (!is_string($invoiceId) || $invoiceId === '') {
            $aviagramGatewayService->storeCallbackAudit(
                $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
                false, 'Order ID not found in payload.',
            );
            return $this->respond($orderId, [
                'responseCode' => '4220001',
                'responseMessage' => 'Order ID not found in callback payload.',
            ], 422, 'order_id_missing');
        }

        // Verify the payload orderId matches the order ID the provider returned at
        // init time (stored in provider_order_id) — the provider echoes its own ID
        // in the callback, not our internal order_id. Fall back to order_id for
        // rows created before provider_order_id was captured.
        $expectedOrderId = isset($transaction->provider_order_id) && (string) $transaction->provider_order_id !== ''
            ? (string) $transaction->provider_order_id
            : $orderId;
        if ($invoiceId !== $expectedOrderId) {
            $aviagramGatewayService->storeCallbackAudit(
                $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
                false, sprintf('Order ID mismatch: received %s, expected %s.', $invoiceId, $expectedOrderId),
            );
            return $this->respond($orderId, [
                'responseCode' => '4220004',
                'responseMessage' => 'Order ID does not match the expected value.',
            ], 422, 'order_id_mismatch');
        }

        // Validate amount (decimal-safe comparison via bccomp).
        $callbackAmount = $normalizedPayload['amount'] ?? null;
        $expectedAmount = (string) $transaction->expected_amount;
        if (!$this->amountsMatch($callbackAmount, $expectedAmount)) {
            $aviagramGatewayService->storeCallbackAudit(
                $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
                false, sprintf(
                    'Amount mismatch: received %s, expected %s.',
                    is_string($callbackAmount) ? $callbackAmount : 'null',
                    $expectedAmount,
                ),
            );
            return $this->respond($orderId, [
                'responseCode' => '4220002',
                'responseMessage' => 'Amount does not match expected value.',
            ], 422, 'amount_mismatch');
        }

        // Validate currency (case-insensitive; strips provider suffixes like "-sp").
        $callbackCurrency = $normalizedPayload['currency'] ?? null;
        $expectedCurrency = (string) $transaction->expected_currency;
        if (!$this->currenciesMatch($callbackCurrency, $expectedCurrency)) {
            $aviagramGatewayService->storeCallbackAudit(
                $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
                false, sprintf(
                    'Currency mismatch: received %s, expected %s.',
                    is_string($callbackCurrency) ? $callbackCurrency : 'null',
                    $expectedCurrency,
                ),
            );
            return $this->respond($orderId, [
                'responseCode' => '4220003',
                'responseMessage' => 'Currency does not match expected value.',
            ], 422, 'currency_mismatch');
        }

        // All checks passed.
        // Dispatch the forward job BEFORE storing audit / consuming the key so that
        // a queue-unavailable error lets Aviagram retry with the same key.
        $callbackUrl = $aviagramGatewayService->getUserCallbackUrlForOrder($orderId);
        if ($callbackUrl !== null) {
            Log::channel('fiat-gateway')->info('aviagram.callback.forward_dispatched', [
                'gateway'             => 'aviagram',
                'order_id'            => $orderId,
                'merchant_callback'   => $callbackUrl,
                'normalized_payload'  => $normalizedPayload,
            ]);
            Bus::dispatch(new ForwardCallbackJob(
                $orderId,
                $callbackUrl,
                $aviagramGatewayService->buildPaymentOutcome($orderId, $normalizedPayload),
            ));
        }

        $aviagramGatewayService->storeCallbackAudit(
            $orderId, $normalizedPayload, $fullUrl, $clientIp, $headers, $rawBody,
            true, null, jobDispatched: $callbackUrl !== null,
        );

        $aviagramGatewayService->markCallbackKeyConsumed($orderId);

        return $this->respond($orderId, [
            'responseCode' => '2010000',
            'responseMessage' => 'Callback accepted.',
        ], 201, 'accepted');
    }

    /**
     * Logs the outbound response (sent back to the gateway) and returns it.
     *
     * @param array<string, mixed> $body
     */
    private function respond(?string $orderId, array $body, int $status, string $outcome): JsonResponse
    {
        Log::channel('fiat-gateway')->info('aviagram.callback.responded', [
            'gateway'        => 'aviagram',
            'order_id'       => $orderId,
            'outcome'        => $outcome,
            'response_status' => $status,
            'response_body'  => $body,
        ]);

        return new JsonResponse($body, $status);
    }

    /**
     * Redacts sensitive header values before they are persisted.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function sanitizeHeaders(array $headers): array
    {
        foreach (self::MASKED_HEADERS as $name) {
            if (array_key_exists($name, $headers)) {
                $headers[$name] = ['[REDACTED]'];
            }
        }
        return $headers;
    }

    /**
     * Decimal-safe amount comparison. Normalises "100", "100.0", and "100.00"
     * as equal by delegating to bccomp with eight decimal places of precision.
     */
    private function amountsMatch(mixed $received, string $expected): bool
    {
        if (!is_string($received) && !is_numeric($received)) {
            return false;
        }
        $received = (string) $received;
        if (!is_numeric($received) || !is_numeric($expected)) {
            return false;
        }
        return bccomp($received, $expected, 8) === 0;
    }

    /**
     * Case-insensitive currency comparison that also strips provider-specific
     * suffixes (e.g. Aviagram's "eur-sp" is treated as "EUR").
     */
    private function currenciesMatch(mixed $received, string $expected): bool
    {
        if (!is_string($received)) {
            return false;
        }
        return strcasecmp(
            $this->normalizeCurrencyCode($received),
            $this->normalizeCurrencyCode($expected),
        ) === 0;
    }

    /** Uppercases and strips provider-specific suffixes ("eur-sp" → "EUR"). */
    private function normalizeCurrencyCode(string $currency): string
    {
        return strtoupper(explode('-', $currency)[0]);
    }
}
