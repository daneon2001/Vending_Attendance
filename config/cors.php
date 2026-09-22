<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) (env('APP_ENV') === 'beta'
        ? env('BETA_ALLOWED_ORIGIN', 'https://localhost')
        : env('VENDING_CORS_ALLOWED_ORIGINS', 'https://localhost,capacitor://localhost'))),
)));

// Reject wildcard/malformed origins rather than widening access by mistake.
$origins = array_values(array_filter($origins, static function (string $origin): bool {
    $url = parse_url($origin);
    return is_array($url) && in_array($url['scheme'] ?? '', ['https', 'http', 'capacitor'], true)
        && ! str_contains($origin, '*') && ! isset($url['user']) && ! isset($url['pass'])
        && ! isset($url['path']) && ! isset($url['query']) && ! isset($url['fragment'])
        && isset($url['host']) && (env('APP_ENV') !== 'beta' || $url['scheme'] === 'https');
}));

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
