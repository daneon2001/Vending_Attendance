<?php

return [
    'fortia' => [
        'allow_write' => filter_var(env('FORTIA_EMPLOYEES_ALLOW_WRITE', false), FILTER_VALIDATE_BOOL),
        'max_records' => (int) env('FORTIA_EMPLOYEES_MAX_RECORDS', 5000),
        'http_contract_approved' => filter_var(env('FORTIA_EMPLOYEES_HTTP_CONTRACT_APPROVED', false), FILTER_VALIDATE_BOOL),
        'employees_path' => env('FORTIA_EMPLOYEES_PATH'),
        'api_token' => env('FORTIA_EMPLOYEES_API_TOKEN'),
    ],
    'import' => [
        'max_file_kb' => (int) env('EMPLOYEE_IMPORT_MAX_FILE_KB', 5120),
        'max_rows' => (int) env('EMPLOYEE_IMPORT_MAX_ROWS', 5000),
        'preview_per_page' => (int) env('EMPLOYEE_IMPORT_PREVIEW_PER_PAGE', 50),
        'staging_ttl_hours' => (int) env('EMPLOYEE_IMPORT_STAGING_TTL_HOURS', 24),
        'xlsx_max_entries' => (int) env('EMPLOYEE_IMPORT_XLSX_MAX_ENTRIES', 256),
        'xlsx_max_uncompressed_bytes' => (int) env('EMPLOYEE_IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES', 52428800),
        'legacy_enabled' => filter_var(env('EMPLOYEE_LEGACY_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    'pilot' => [
        'allow_production' => filter_var(env('VENDING_PILOT_USERS_ALLOW_PRODUCTION', false), FILTER_VALIDATE_BOOL),
        'users' => [
            'admin' => [
                'name' => 'Pilot Admin',
                'email' => 'pilot.admin@example.test',
                'password' => env('VENDING_PILOT_ADMIN_PASSWORD'),
            ],
            'operator' => [
                'name' => 'Pilot Operator',
                'email' => 'pilot.operator@example.test',
                'password' => env('VENDING_PILOT_OPERATOR_PASSWORD'),
            ],
            'support' => [
                'name' => 'Pilot Support',
                'email' => 'pilot.support@example.test',
                'password' => env('VENDING_PILOT_SUPPORT_PASSWORD'),
            ],
            'viewer' => [
                'name' => 'Pilot Viewer',
                'email' => 'pilot.viewer@example.test',
                'password' => env('VENDING_PILOT_VIEWER_PASSWORD'),
            ],
        ],
    ],
];
