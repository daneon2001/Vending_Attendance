<?php

return [
    'vending' => [
        'url' => env('SYBI_VENDING_API_URL'),
        'token' => env('SYBI_VENDING_API_TOKEN'),
        'connect_timeout' => max(1, (int) env('SYBI_VENDING_API_CONNECT_TIMEOUT', 5)),
        'timeout' => max(1, (int) env('SYBI_VENDING_API_TIMEOUT', 30)),
        'sync_enabled' => filter_var(env('SYBI_VENDING_SYNC_ENABLED', false), FILTER_VALIDATE_BOOL),
        'sync_interval_minutes' => max(1, (int) env('SYBI_VENDING_SYNC_INTERVAL_MINUTES', 60)),
        'catalog_authoritative' => filter_var(env('SYBI_VENDING_CATALOG_AUTHORITATIVE', true), FILTER_VALIDATE_BOOL),
    ],
];
