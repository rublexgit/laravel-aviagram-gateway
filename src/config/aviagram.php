<?php

return [
    'base_url' => env('AVIAGRAM_BASE_URL', 'https://aviagram.app'),
    'client_id' => env('AVIAGRAM_CLIENT_ID'),
    'client_secret' => env('AVIAGRAM_CLIENT_SECRET'),

    'http' => [
        'timeout' => env('AVIAGRAM_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('AVIAGRAM_HTTP_CONNECT_TIMEOUT', 10),
        'proxy' => env('AVIAGRAM_HTTP_PROXY'),
        'verify' => env('AVIAGRAM_HTTP_VERIFY', true),
    ],
];
