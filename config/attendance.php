<?php

return [
    'sources' => [
        'sync',
        'manual',
        'import',
        'api',
    ],

    'statuses' => [
        'valida',
        'anulada',
        'corregida',
    ],

    'consolidation' => [
        // First check-in / last check-out by default.
        'entry_log_types' => [1],
        'exit_log_types' => [2, 4],

        // Remove duplicated marks when they happen within this window.
        'dedup_window_seconds' => 90,

        // Operational tolerances. Values are in minutes.
        'entry_tolerance_minutes' => 10,
        'exit_tolerance_minutes' => 10,

        // Use all marks for the day and keep totals in payload.
        'allow_multiple_in_out' => true,
    ],
];
