<?php

namespace Tests\Feature;

use App\Actions\SyncPermissionCatalog;
use App\Models\AuditCleanupSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditCleanupModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SyncPermissionCatalog::run();
    }

    public function test_audit_cleanup_page_requires_manage_permission(): void
    {
        $authorizedUser = $this->createUserWithAuditPermissions(['manage']);
        $unauthorizedUser = $this->createUserWithAuditPermissions(['view']);

        $this->actingAs($authorizedUser)
            ->get(route('settings.audit-cleanup.page'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/AuditCleanup/Index')
                ->has('settings')
                ->has('dashboard.stats')
            );

        $this->actingAs($unauthorizedUser)
            ->get(route('settings.audit-cleanup.page'))
            ->assertForbidden();
    }

    public function test_cleanup_preview_and_execute_preserve_critical_history(): void
    {
        $user = $this->createUserWithAuditPermissions(['manage']);

        $criticalId = $this->insertAuditLog('auth.login.failed', now()->subDays(40), 'login', 'users');
        $importantId = $this->insertAuditLog('employees.sync', now()->subDays(40), 'sync', 'employees');
        $noiseId = $this->insertAuditLog('onprem.heartbeat.sampled', now()->subDays(40), 'heartbeat', 'devices');
        $freshNoiseId = $this->insertAuditLog('onprem.heartbeat.sampled', now()->subMinutes(10), 'heartbeat', 'devices');

        $payload = [
            'selection' => [
                'delete_except_critical' => true,
                'before_date' => now()->toDateString(),
                'optimize' => false,
                'simulate' => true,
            ],
        ];

        $previewResponse = $this->actingAs($user)
            ->postJson('/api/audit-cleanup/preview', $payload);

        $previewResponse->assertOk()
            ->assertJsonPath('data.mode', 'selection')
            ->assertJsonPath('data.records_to_delete', 2);

        $executeResponse = $this->actingAs($user)
            ->postJson('/api/audit-cleanup/execute', $payload);

        $executeResponse->assertOk()
            ->assertJsonPath('data.deleted_records', 2)
            ->assertJsonPath('data.optimized', false);

        $this->assertDatabaseHas('audit_logs', ['id' => $criticalId, 'event' => 'auth.login.failed']);
        $this->assertDatabaseMissing('audit_logs', ['id' => $importantId]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $noiseId]);
        $this->assertDatabaseHas('audit_logs', ['id' => $freshNoiseId]);

        $this->assertDatabaseHas('audit_cleanup_runs', [
            'trigger_source' => 'manual',
            'status' => 'completed',
            'deleted_records' => 2,
        ]);
    }

    public function test_audit_cleanup_command_deletes_old_noise_by_retention(): void
    {
        AuditCleanupSetting::singleton()->update([
            'critical_retention_days' => 3650,
            'important_retention_days' => 3650,
            'noise_retention_days' => 7,
            'batch_size' => 100,
            'heartbeat_log_interval_minutes' => 30,
            'optimize_min_deleted_mb' => 4096,
        ]);

        $oldNoiseId = $this->insertAuditLog('onprem.heartbeat.sampled', now()->subDays(20), 'heartbeat', 'devices');
        $recentNoiseId = $this->insertAuditLog('onprem.heartbeat.sampled', now()->subDays(2), 'heartbeat', 'devices');
        $importantId = $this->insertAuditLog('employees.sync', now()->subDays(20), 'sync', 'employees');

        $this->artisan('audit:cleanup')
            ->expectsOutput('Limpieza de bitacora completada.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $oldNoiseId]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recentNoiseId]);
        $this->assertDatabaseHas('audit_logs', ['id' => $importantId]);
        $this->assertDatabaseHas('audit_cleanup_runs', [
            'trigger_source' => 'scheduled',
            'status' => 'completed',
            'deleted_records' => 1,
        ]);
    }

    private function createUserWithAuditPermissions(array $actions): User
    {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => 'Audit '.uniqid(),
            'description' => 'Role for audit cleanup tests',
            'is_system' => false,
        ]);

        $permissionIds = Permission::query()
            ->where('module', 'audit')
            ->whereIn('action', $actions)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($permissionIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh('roles.permissions');
    }

    private function insertAuditLog(string $event, \Carbon\CarbonInterface $createdAt, ?string $action = null, ?string $entity = null): int
    {
        return (int) DB::table('audit_logs')->insertGetId([
            'event' => $event,
            'action' => $action,
            'entity' => $entity,
            'description' => $event,
            'reason' => $event,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
