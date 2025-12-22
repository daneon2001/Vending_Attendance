<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('roles:id,name,is_system');

        if ($search = $request->string('search')->trim()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->string('status')->toString() !== 'todos') {
            $desired = strtolower($request->string('status')->toString());
            $isActive = in_array($desired, ['activo', 'active', '1'], true);
            $query->where('estatus', $isActive ? 1 : 0);
        }

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = max(5, min($perPage, 50));

        $users = $query
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return UserResource::collection($users)->response();
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'estatus' => 1,
        ]);

        $user->roles()->sync([$data['role_id']]);

        AuditLogger::log(
            'users.created',
            $user,
            'Usuario creado',
            [
                'attributes' => Arr::except($data, ['password', 'password_confirmation']),
            ]
        );

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $before = $user->only(['name', 'email', 'estatus', 'role_id' => $user->roles()->first()?->id]);

        $user->name = $data['name'];
        $user->email = $data['email'];

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();
        $user->roles()->sync([$data['role_id']]);

        $user->refresh();
        AuditLogger::log(
            'users.updated',
            $user,
            'Usuario actualizado',
            [
                'before' => $before,
                'after' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'estatus' => $user->estatus,
                    'role_id' => $user->roles()->first()?->id,
                ],
            ]
        );

        return (new UserResource($user->load('roles')))->response();
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactivo,inactive,activo'],
        ]);

        $shouldActivate = in_array($validated['status'], ['active', 'activo'], true);
        $beforeStatus = $user->estatus ? 'activo' : 'inactivo';

        if (! $shouldActivate && $user->id === $request->user()?->id) {
            throw ValidationException::withMessages([
                'status' => 'No puedes desactivar tu propia cuenta.',
            ]);
        }

        if (! $shouldActivate) {
            $this->ensureNotLastAdmin($user);
        }

        $user->estatus = $shouldActivate ? 1 : 0;
        $user->save();

        AuditLogger::log(
            'users.status_changed',
            $user,
            $shouldActivate ? 'Usuario activado' : 'Usuario desactivado',
            [
                'before' => $beforeStatus,
                'after' => $shouldActivate ? 'activo' : 'inactivo',
            ]
        );

        return (new UserResource($user->load('roles')))->response();
    }

    protected function ensureNotLastAdmin(User $user): void
    {
        $adminRole = Role::where('name', 'Administrador')->first();

        if (! $adminRole) {
            return;
        }

        $isAdmin = $user->roles()->where('roles.id', $adminRole->id)->exists();

        if (! $isAdmin) {
            return;
        }

        $activeAdmins = $adminRole->users()
            ->where('users.estatus', 1)
            ->where('users.id', '!=', $user->id)
            ->count();

        if ($activeAdmins === 0) {
            throw ValidationException::withMessages([
                'status' => 'Debe existir al menos un administrador activo en el sistema.',
            ]);
        }
    }
}
