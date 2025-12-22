<?php

return [
    'super_admin_email' => env('ADMIN_EMAIL', 'admin@asistencias.test'),
    'modules' => [
        'dashboard' => [
            'label' => 'Panel general',
            'actions' => ['view', 'export', 'manage'],
        ],
        'clocks' => [
            'label' => 'Relojes biométricos',
            'actions' => ['view', 'create', 'update', 'delete', 'disable', 'sync', 'export', 'manage'],
        ],
        'locations' => [
            'label' => 'Sucursales / Unidades',
            'actions' => ['view', 'create', 'update', 'delete', 'disable', 'manage'],
        ],
        'employees' => [
            'label' => 'Catálogo de empleados',
            'actions' => ['view', 'create', 'update', 'delete', 'disable', 'sync', 'export', 'manage'],
        ],
        'attendance' => [
            'label' => 'Asistencias y reportes',
            'actions' => ['view', 'export', 'sync', 'manage'],
        ],
        'settings' => [
            'label' => 'Configuración',
            'actions' => ['view', 'create', 'update', 'delete', 'manage'],
        ],
        'roles' => [
            'label' => 'Roles y permisos',
            'actions' => ['view', 'create', 'update', 'delete', 'manage'],
        ],
        'users' => [
            'label' => 'Usuarios del sistema',
            'actions' => ['view', 'create', 'update', 'delete', 'disable', 'manage'],
        ],
        'audit' => [
            'label' => 'Bitácora de auditoría',
            'actions' => ['view', 'manage'],
        ],
    ],
    'standard_actions' => ['view', 'create', 'update', 'delete', 'disable', 'export', 'sync', 'manage'],
];
