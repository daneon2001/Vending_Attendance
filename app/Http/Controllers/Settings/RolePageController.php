<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RolePageController extends Controller
{
    public function __invoke(Request $request)
    {
        $roles = Role::with(['permissions', 'users:id,name,email,estatus'])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Roles/Index', [
            'roles' => RoleResource::collection($roles)->resolve(),
            'modules' => config('permissions.modules'),
            'users' => User::select('id', 'name', 'email')->orderBy('name')->get(),
        ]);
    }
}
