<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'estatus',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'estatus' => 'boolean',
        ];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function allPermissions()
    {
        $roles = $this->relationLoaded('roles')
            ? $this->roles->loadMissing('permissions')
            : $this->roles()->with('permissions')->get();

        return $roles
            ->pluck('permissions')
            ->flatten()
            ->unique(fn ($permission) => $permission->module.'-'.$permission->action);
    }

    public function hasPermission(string $module, string $action): bool
    {
        $permissions = $this->relationLoaded('roles')
            ? $this->roles->loadMissing('permissions')
            : $this->roles()->with('permissions')->get();

        foreach ($permissions as $role) {
            foreach ($role->permissions as $permission) {
                if ($permission->module === $module && ($permission->action === $action || $permission->action === 'manage')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function permissionsMatrix(): array
    {
        $matrix = [];
        $permissions = $this->allPermissions();

        foreach ($permissions as $permission) {
            $matrix[$permission->module][] = $permission->action;
        }

        return collect($matrix)
            ->map(fn ($actions) => array_values(array_unique($actions)))
            ->toArray();
    }
}
