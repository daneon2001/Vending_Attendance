<?php

return [
    'hmac_tolerance_seconds' => (int) env('ONPREM_HMAC_TOLERANCE_SECONDS', 300),
    'nonce_ttl_seconds' => (int) env('ONPREM_NONCE_TTL_SECONDS', 600),
    'max_batch_size' => max(1, (int) env('ONPREM_MAX_BATCH_SIZE', 500)),
    'config_overrides' => [
        'heartbeat_interval_seconds' => env('ONPREM_HEARTBEAT_INTERVAL_SECONDS'),
        'max_batch_size' => env('ONPREM_MAX_BATCH_SIZE'),
    ],
];
