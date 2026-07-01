<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsIndexController extends Controller
{
    public function __invoke(Request $request)
    {
        return Inertia::render('Settings/Index', [
            'sections' => [
                [
                    'label' => 'Roles y permisos',
                    'description' => 'Administra el acceso a cada módulo del sistema.',
                    'route' => route('settings.roles.page'),
                    'available' => $request->user()?->hasPermission('settings', 'manage') ?? false,
                ],
                [
                    'label' => 'Usuarios del sistema',
                    'description' => 'Gestiona cuentas, roles y accesos de usuarios.',
                    'route' => route('settings.users.page'),
                    'available' => $request->user()?->hasPermission('users', 'view') ?? false,
                ],
                [
                    'label' => 'Bitácora de auditoría',
                    'description' => 'Consulta el historial de acciones y cambios.',
                    'route' => route('settings.audit.page'),
                    'available' => $request->user()?->hasPermission('audit', 'view') ?? false,
                ],
                [
                    'label' => 'Limpieza de bitácora',
                    'description' => 'Configura retención, simulaciones y purgas seguras.',
                    'route' => route('settings.audit-cleanup.page'),
                    'available' => $request->user()?->hasPermission('audit', 'manage') ?? false,
                ],
            ],
        ]);
    }
}
