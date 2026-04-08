<?php

namespace Aviagram\Http\Controllers;

use Aviagram\Services\AviagramGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class CallbackController
{
    private const FORWARD_TIMEOUT_SECONDS = 30;

    public function handle(Request $request, AviagramGatewayService $aviagramGatewayService): JsonResponse
    {
        $normalizedPayload = $aviagramGatewayService->normalizeCallbackPayload($request->all());
        $orderId = $normalizedPayload['orderId'];
        if (!is_string($orderId) || $orderId === '') {
            return new JsonResponse([
                'responseCode' => '4000001',
                'responseMessage' => 'Order ID not found in callback payload.',
            ], 400);
        }

        $callbackUrl = $aviagramGatewayService->getUserCallbackUrlForOrder($orderId);
        if ($callbackUrl === null) {
            return new JsonResponse([
                'responseCode' => '4040001',
                'responseMessage' => 'User callback URL not found for order ID.',
            ], 404);
        }

        try {
            $forwardResponse = Http::acceptJson()
                ->asJson()
                ->timeout(self::FORWARD_TIMEOUT_SECONDS)
                ->post($callbackUrl, $normalizedPayload);
        } catch (Throwable $exception) {
            $aviagramGatewayService->storeCallbackResult(
                $orderId,
                $normalizedPayload,
                false,
                null,
                $exception->getMessage()
            );

            return new JsonResponse([
                'responseCode' => '5000001',
                'responseMessage' => 'Callback forwarding failed.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        $aviagramGatewayService->storeCallbackResult(
            $orderId,
            $normalizedPayload,
            $forwardResponse->successful(),
            $forwardResponse->status()
        );

        if ($forwardResponse->successful()) {
            $aviagramGatewayService->forgetUserCallbackUrlForOrder($orderId);
        }

        return new JsonResponse([
            'responseCode' => '2000000',
            'responseMessage' => 'Callback processed.',
        ]);
    }
}
