<?php

return [
    'demo' => [
        'admin_password' => env('VENDING_DEMO_ADMIN_PASSWORD'),
    ],

    'device' => [
        'provisioning_token_ttl_minutes' => max(5, (int) env('VENDING_PROVISIONING_TOKEN_TTL_MINUTES', 30)),
        'clock_drift_warning_seconds' => max(30, (int) env('VENDING_CLOCK_DRIFT_WARNING_SECONDS', 300)),
        'next_heartbeat_seconds' => max(15, (int) env('VENDING_HEARTBEAT_INTERVAL_SECONDS', 60)),
        'rate_limits' => [
            'provision_per_minute' => max(1, (int) env('VENDING_PROVISION_PER_MINUTE', 5)),
            'bootstrap_per_minute' => max(1, (int) env('VENDING_BOOTSTRAP_PER_MINUTE', 30)),
            'heartbeat_per_minute' => max(1, (int) env('VENDING_HEARTBEAT_PER_MINUTE', 120)),
        ],
    ],
    'manifests' => [
        'stale_after_minutes' => max(1, (int) env('VENDING_MANIFEST_STALE_AFTER_MINUTES', 10)),
        'stale_version_lag' => max(1, (int) env('VENDING_MANIFEST_STALE_VERSION_LAG', 3)),
        'rate_limits' => [
            'status_per_minute' => max(1, (int) env('VENDING_MANIFEST_STATUS_PER_MINUTE', 60)),
            'download_per_minute' => max(1, (int) env('VENDING_MANIFEST_DOWNLOAD_PER_MINUTE', 30)),
            'ack_per_minute' => max(1, (int) env('VENDING_MANIFEST_ACK_PER_MINUTE', 60)),
        ],
    ],
    'attendance' => [
        'batch_max_events' => max(1, (int) env('VENDING_ATTENDANCE_BATCH_MAX_EVENTS', 100)),
        'future_tolerance_seconds' => max(0, (int) env('VENDING_ATTENDANCE_FUTURE_TOLERANCE_SECONDS', 300)),
        'minimum_captured_year' => max(1970, (int) env('VENDING_ATTENDANCE_MINIMUM_CAPTURED_YEAR', 2000)),
        'auth_audit_per_minute' => max(1, (int) env('VENDING_ATTENDANCE_AUTH_AUDIT_PER_MINUTE', 10)),
        'rate_limits' => [
            'single_per_minute' => max(1, (int) env('VENDING_ATTENDANCE_SINGLE_PER_MINUTE', 120)),
            'batch_per_minute' => max(1, (int) env('VENDING_ATTENDANCE_BATCH_PER_MINUTE', 30)),
        ],
    ],
];
