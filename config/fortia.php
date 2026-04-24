<?php

return [
    'base_url'    => env('FORTIA_BASE_URL', 'https://fortia.example.com'),
    'username'    => env('FORTIA_USERNAME', 'fortia_user'),
    'password'    => env('FORTIA_PASSWORD', 'fortia_pass'),
    'company_id'  => env('FORTIA_COMPANY_ID', 1),
    'dummy_token' => env('FORTIA_DUMMY_TOKEN', 'FORTIA-DUMMY-TOKEN'),
    'sync_driver' => env('FORTIA_SYNC_DRIVER', env('APP_ENV', 'production') === 'local' ? 'mock' : 'fortia'),
    'sync_connection' => env('FORTIA_SYNC_CONNECTION', 'fortia'),
    'sync_table' => env('FORTIA_SYNC_TABLE', 'fortia_employees'),
    'clocks_default_company_id' => env('FORTIA_CLOCKS_DEFAULT_COMPANY_ID'),
];
