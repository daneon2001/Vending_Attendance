<?php

namespace App\Models;

use App\Actions\SyncPermissionCatalog;
use App\Enums\Employees\EmployeeSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'employee_id',
        'employee',
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

    /** Optional labor identity; assignment is not exposed through mass assignment. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Read-only identity resolution, not authorization to perform an activity.
     * Never infer a link from email or trust cached/in-memory status or relations.
     */
    public static function authenticatedEmployee(): ?Employee
    {
        $authenticated = auth()->user();
        if (! $authenticated instanceof self || ! $authenticated->exists) {
            return null;
        }

        $account = self::query()->whereKey($authenticated->getAuthIdentifier())
            ->where('estatus', true)->first();
        if ($account === null || $account->employee_id === null) {
            return null;
        }

        $employee = $account->employee()->activeForVending()
            ->where('source', EmployeeSource::FORTIA->value)->first();

        return $employee !== null && trim((string) $employee->source_external_id) !== ''
            ? $employee : null;
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
        $compatibleModules = SyncPermissionCatalog::compatibleModuleKeys($module);

        foreach ($permissions as $role) {
            foreach ($role->permissions as $permission) {
                if (in_array($permission->module, $compatibleModules, true)
                    && ($permission->action === $action || $permission->action === 'manage')) {
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
            $matrix[SyncPermissionCatalog::normalizeModuleKey($permission->module)][] = $permission->action;
        }

        return collect($matrix)
            ->map(fn ($actions) => array_values(array_unique($actions)))
            ->toArray();
    }
}
