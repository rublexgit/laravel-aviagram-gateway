<?php

namespace Aviagram\Services;

use Aviagram\Data\OrderData;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class AviagramGatewayService
{
    private const CREATE_FORM_PATH = '/api/payment/createForm';

    public function createForm(OrderData $order): array
    {
        $missingConfigKeys = $this->getMissingConfigKeys();
        if ($missingConfigKeys !== []) {
            return [
                'responseCode' => '5000002',
                'responseMessage' => 'Aviagram configuration is missing.',
                'missingConfig' => $missingConfigKeys,
            ];
        }

        $response = Http::acceptJson()->asJson()->timeout(30)
            ->withHeaders([
                'Authorization' => $this->authorizationHeader()
            ])
            ->post($this->buildCreateFormUrl(), $order->toArray());

        $decoded = $response->json();
        if (!is_array($decoded)) {
            return [
                'responseCode' => (string) $response->status(),
                'responseMessage' => 'Invalid JSON response from Aviagram.',
            ];
        }

        return $decoded;
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
}
