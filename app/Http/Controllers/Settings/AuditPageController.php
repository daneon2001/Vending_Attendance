<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditPageController extends Controller
{
    public function __invoke(Request $request)
    {
        return Inertia::render('Settings/Audit/Index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }
}
