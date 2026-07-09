<?php

/**
 * Process-wide HTTP tuning, plus the pre-migration credential source.
 *
 * Credentials are stored per gateway in the `fiat_gateways` table and injected
 * into the driver, so that one merchant account per deployment is no longer a
 * constraint. See AviagramGatewayService::credentialSchema().
 */
return [
    'http' => [
        'timeout' => env('AVIAGRAM_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('AVIAGRAM_HTTP_CONNECT_TIMEOUT', 10),
        'proxy' => env('AVIAGRAM_HTTP_PROXY'),
        'verify' => env('AVIAGRAM_HTTP_VERIFY', true),
    ],

    // Read only by `fiat-gateway:import-credentials`, which copies the single
    // env-configured account into a gateway row. The driver never reads these.
    // Safe to delete from .env once the import has run.
    'legacy_env' => [
        'base_url' => env('AVIAGRAM_BASE_URL', 'https://aviagram.app'),
        'client_id' => env('AVIAGRAM_CLIENT_ID'),
        'client_secret' => env('AVIAGRAM_CLIENT_SECRET'),
    ],
];
