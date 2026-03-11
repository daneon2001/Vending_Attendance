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
];
