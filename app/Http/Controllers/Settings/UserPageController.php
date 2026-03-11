<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserPageController extends Controller
{
    public function __invoke(Request $request)
    {
        $roles = Role::orderBy('name')
            ->get(['id', 'name', 'is_system']);

        return Inertia::render('Settings/Users/Index', [
            'roles' => $roles,
        ]);
    }
}
