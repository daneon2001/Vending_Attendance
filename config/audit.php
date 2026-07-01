<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Log Protections
    |--------------------------------------------------------------------------
    |
    | By default audit logs are append-only. This protects evidentiary data
    | against accidental or malicious tampering from the application layer.
    |
    */
    'prevent_updates' => env('AUDIT_PREVENT_UPDATES', true),
    'prevent_deletes' => env('AUDIT_PREVENT_DELETES', true),

    /*
    |--------------------------------------------------------------------------
    | Extreme Override (Use only for legal/forensic exceptional workflows)
    |--------------------------------------------------------------------------
    */
    'allow_extreme_modification' => env('AUDIT_ALLOW_EXTREME_MODIFICATION', false),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */
    'retention_months' => (int) env('AUDIT_RETENTION_MONTHS', 24),

    /*
    |--------------------------------------------------------------------------
    | Audit Cleanup Defaults
    |--------------------------------------------------------------------------
    */
    'cleanup' => [
        'critical_retention_days' => (int) env('AUDIT_CRITICAL_RETENTION_DAYS', 3650),
        'important_retention_days' => (int) env('AUDIT_IMPORTANT_RETENTION_DAYS', 180),
        'noise_retention_days' => (int) env('AUDIT_NOISE_RETENTION_DAYS', 7),
        'batch_size' => (int) env('AUDIT_CLEANUP_BATCH_SIZE', 5000),
        'heartbeat_log_interval_minutes' => (int) env('AUDIT_HEARTBEAT_LOG_INTERVAL_MINUTES', 30),
        'optimize_min_deleted_mb' => (int) env('AUDIT_OPTIMIZE_MIN_DELETED_MB', 512),
        'min_keep_days' => (int) env('AUDIT_PURGE_MIN_KEEP_DAYS', 90),
        'purge_batch_size' => (int) env('AUDIT_PURGE_BATCH_SIZE', 20000),
        'purge_request_timeout_seconds' => (int) env('AUDIT_PURGE_REQUEST_TIMEOUT_SECONDS', 3600),
        'schedule_time' => env('AUDIT_CLEANUP_SCHEDULE_TIME', '03:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Classification
    |--------------------------------------------------------------------------
    */
    'classification' => [
        'critical' => [
            'events' => [
                'auth.login.failed',
                'auth.login.success',
                'users.created',
                'users.updated',
                'users.status_changed',
                'roles.created',
                'roles.updated',
                'roles.deleted',
                'roles.users_assigned',
                'roles.user_detached',
                'clocks.created',
                'clocks.updated',
                'clocks.location_assigned',
                'companies.created',
                'companies.updated',
                'companies.status_changed',
                'units.created',
                'units.updated',
                'units.status_changed',
                'employees.face_profile_updated',
                'employees.face_profile_cleared',
                'employees.status_changed',
                'biometric.face.deleted',
                'biometric.face.updated',
                'biometric.fingerprint.deleted',
                'biometric.fingerprints.templates.accessed',
                'biometric.fingerprints.metadata.accessed',
                'onprem.punch.rejected',
            ],
            'patterns' => [
                'login',
                'logout',
                'password',
                'permission',
                'role',
                'user',
                'company',
                'unit',
                'clock',
                'fingerprint',
                'biometric',
                'face profile',
                'rejected',
                'error',
                'failed',
            ],
        ],
        'important' => [
            'events' => [
                'attendance.record.created',
                'attendance.record.updated',
                'attendance.record.deleted',
                'attendance.incidence.created',
                'attendance.incidence.updated',
                'attendance.incidence.deleted',
                'attendance.records.exported',
                'attendance.checks_exported',
                'employees.sync',
                'employees.import_preview',
                'employees.import_catalogs_detected',
                'employees.import_catalogs_created',
                'employees.import_preview_recalculated',
                'employees.import_failed',
                'employees.import_completed',
                'onprem.punch.received',
                'onprem.heartbeat.status_changed',
                'units.exported',
                'units.bulk_deactivated',
            ],
            'patterns' => [
                'sync',
                'import',
                'attendance',
                'correction',
                'export',
                'enrol',
                'status changed',
                'heartbeat status',
            ],
        ],
        'noise' => [
            'events' => [
                'onprem.heartbeat.received',
                'onprem.heartbeat.sampled',
            ],
            'patterns' => [
                'heartbeat received',
                'heartbeat ok',
                'monitor tick',
                'health check',
                'device alive',
                'ping',
            ],
        ],
        'selectors' => [
            'heartbeat' => [
                'events' => ['onprem.heartbeat.received', 'onprem.heartbeat.sampled', 'onprem.heartbeat.status_changed'],
                'patterns' => ['heartbeat', 'device alive', 'health check', 'monitor tick', 'ping'],
            ],
            'sync' => [
                'events' => ['employees.sync'],
                'patterns' => ['sync', 'catalog', 'import'],
            ],
            'login' => [
                'events' => ['auth.login.failed', 'auth.login.success'],
                'patterns' => ['login', 'logout', 'auth'],
            ],
        ],
    ],
];
