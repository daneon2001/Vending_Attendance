<?php

return [
    // Both environment=beta AND this switch are required on a server.
    'enabled' => env('INTERNAL_BETA_ENABLED', false),
    'testers_enabled' => env('INTERNAL_BETA_TESTERS_ENABLED', false),
    'registry_path' => env('BETA_TESTER_REGISTRY_PATH') ?: storage_path('app/private/beta-onboarding/testers.json'),
    'cleanup_enabled' => env('BETA_CLEANUP_ENABLED', false),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('BETA_TRUSTED_PROXIES', ''))))),
];
