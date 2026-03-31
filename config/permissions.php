<?php

return [
    'super_admin_email' => env('ADMIN_EMAIL', 'admin@asistencias.test'),
    'modules' => [
        'dashboard' => [
            'label' => 'Panel general',
            'actions' => ['view', 'export', 'manage'],
        ],
        'clocks' => [
            'label' => 'Relojes biometricos',
            'actions' => ['view', 'create', 'update', 'delete', 'disable', 'sync', 'export', 'manage'],
        ],
        'companies' => [
            'label' => 'Catalogo de empresas',
            'actions' => ['view', 'create', 'update', 'disable', 'manage'],
        ],
        'units' => [
            'label' => 'Catalogo de unidades',
            'actions' => ['view', 'create', 'update', 'disable', 'manage'],
        ],
        'employees' => [
            'label' => 'Catalogo de empleados',
            'actions' => ['view', 'create', 'update', 'delete', 'disable', 'sync', 'export', 'manage'],
        ],
        'attendance' => [
            'label' => 'Asistencias y reportes',
            'actions' => ['view', 'export', 'sync', 'manage'],
        ],
        'asistencias' => [
            'label' => 'Central de asistencias',
            'actions' => ['view', 'export', 'edit', 'admin'],
        ],
        'settings' => [
            'label' => 'Configuracion',
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
            'label' => 'Bitacora de auditoria',
            'actions' => ['view', 'manage'],
        ],
        'biometrics' => [
            'label' => 'Acceso biometrico',
            'actions' => ['fingerprints.read', 'fingerprints.delete', 'templates.read', 'face.manage'],
        ],
    ],
    'standard_actions' => ['view', 'create', 'update', 'delete', 'disable', 'export', 'sync', 'manage'],
];
