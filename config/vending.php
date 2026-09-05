<?php

return [
    'pilot' => [
        'scheduler_supervised' => filter_var(env('PILOT_SCHEDULER_SUPERVISED', false), FILTER_VALIDATE_BOOL),
        'mobile_api_url' => env('PILOT_MOBILE_API_URL', ''),
    ],

    'demo' => [
        'admin_password' => env('VENDING_DEMO_ADMIN_PASSWORD'),
    ],

    'device' => [
        'provisioning_token_ttl_minutes' => max(5, (int) env('VENDING_PROVISIONING_TOKEN_TTL_MINUTES', 30)),
        'clock_drift_warning_seconds' => max(30, (int) env('VENDING_CLOCK_DRIFT_WARNING_SECONDS', 300)),
        'next_heartbeat_seconds' => max(15, (int) env('VENDING_HEARTBEAT_INTERVAL_SECONDS', 60)),
        'health' => [
            'degraded_after_seconds' => max(60, (int) env('VENDING_DEVICE_DEGRADED_AFTER_SECONDS', 180)),
            'offline_after_seconds' => max(120, (int) env('VENDING_DEVICE_OFFLINE_AFTER_SECONDS', 600)),
            'clock_drift_seconds' => max(30, (int) env('VENDING_DEVICE_DEGRADED_CLOCK_DRIFT_SECONDS', 300)),
            'pending_events_count' => max(1, (int) env('VENDING_DEVICE_DEGRADED_PENDING_EVENTS', 100)),
            'storage_free_mb' => max(1, (int) env('VENDING_DEVICE_DEGRADED_STORAGE_FREE_MB', 256)),
            'recent_error_minutes' => max(1, (int) env('VENDING_DEVICE_RECENT_ERROR_MINUTES', 30)),
        ],
        'nonce_prune_batch_size' => max(100, (int) env('VENDING_NONCE_PRUNE_BATCH_SIZE', 5000)),
        'rate_limits' => [
            'provision_per_minute' => max(1, (int) env('VENDING_PROVISION_PER_MINUTE', 5)),
            'bootstrap_per_minute' => max(1, (int) env('VENDING_BOOTSTRAP_PER_MINUTE', 30)),
            'heartbeat_per_minute' => max(1, (int) env('VENDING_HEARTBEAT_PER_MINUTE', 120)),
        ],
    ],
    'fleet' => [
        'alerts_limit' => max(10, (int) env('VENDING_FLEET_ALERTS_LIMIT', 100)),
        'devices_per_page' => min(100, max(10, (int) env('VENDING_FLEET_DEVICES_PER_PAGE', 50))),
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
