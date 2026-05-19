<?php

return [
    'timezone' => env('OPERATIONS_TIMEZONE', env('APP_TIMEZONE', 'America/Mexico_City')),
    'timezone_label' => env('OPERATIONS_TIMEZONE_LABEL', 'Hora centro de Mexico'),
    'storage_timezone' => env('OPERATIONS_STORAGE_TIMEZONE', 'UTC'),
];
