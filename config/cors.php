<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('VENDING_CORS_ALLOWED_ORIGINS', 'https://localhost,capacitor://localhost')),
)));

return [
    'paths' => ['api/v1/device/*'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept', 'Content-Type', 'X-Device-Id', 'X-Timestamp', 'X-Nonce', 'X-Signature',
    ],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
