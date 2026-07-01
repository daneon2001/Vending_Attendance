<?php

namespace Tests\Feature;

use App\Actions\SyncPermissionCatalog;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SyncPermissionCatalog::run();
    }

    public function test_settings_audit_page_renders_for_authorized_user(): void
    {
        $user = $this->createUserWithAuditPermissions(['view']);

        $this->actingAs($user)
            ->get(route('settings.audit.page'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Audit/Index')
                ->has('users')
            );
    }

    public function test_settings_audit_logs_endpoint_uses_lightweight_simple_pagination(): void
    {
        $user = $this->createUserWithAuditPermissions(['view']);

        foreach (range(1, 30) as $index) {
            AuditLog::query()->create([
                'event' => 'users.updated',
                'action' => 'update',
                'entity' => 'users',
                'entity_id' => (string) $index,
                'description' => 'Cambio de usuario '.$index,
                'reason' => 'Prueba',
                'user_name' => 'Administrador',
                'user_email' => 'admin@example.test',
                'metadata' => ['payload' => str_repeat('x', 4000)],
                'old_values' => ['before' => str_repeat('a', 2000)],
                'new_values' => ['after' => str_repeat('b', 2000)],
                'ip_address' => '127.0.0.1',
                'created_at' => now()->subMinutes(30 - $index),
                'updated_at' => now()->subMinutes(30 - $index),
            ]);
        }

        $response = $this->actingAs($user)
            ->getJson('/settings/audit-logs?range=all&page=1&per_page=15');

        $response->assertOk()
            ->assertJsonPath('meta.pagination_type', 'simple')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.has_more_pages', true)
            ->assertJsonCount(15, 'data');

        $first = $response->json('data.0');

        $this->assertArrayHasKey('event', $first);
        $this->assertArrayHasKey('action', $first);
        $this->assertArrayHasKey('ip_address', $first);
        $this->assertArrayNotHasKey('metadata', $first);
        $this->assertArrayNotHasKey('old_values', $first);
        $this->assertArrayNotHasKey('new_values', $first);
    }

    public function test_settings_audit_logs_endpoint_formats_created_at_in_operations_timezone(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('operations.timezone', 'America/Mexico_City');

        $user = $this->createUserWithAuditPermissions(['view']);

        DB::table('audit_logs')->insert([
            'event' => 'users.updated',
            'action' => 'update',
            'entity' => 'users',
            'entity_id' => '1',
            'description' => 'Cambio de usuario timezone test',
            'created_at' => Carbon::parse('2026-07-01 01:00:00', 'UTC')->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::parse('2026-07-01 01:00:00', 'UTC')->format('Y-m-d H:i:s'),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/settings/audit-logs?range=all&page=1&per_page=15&q=timezone%20test');

        $response->assertOk()
            ->assertJsonPath('data.0.created_at_local', '30/06/2026 19:00:00');
    }

    public function test_audit_logs_purge_supports_dry_run_and_real_delete(): void
    {
        $user = $this->createUserWithAuditPermissions(['manage']);

        $oldLogId = DB::table('audit_logs')->insertGetId([
            'event' => 'onprem.heartbeat.sampled',
            'action' => 'heartbeat',
            'entity' => 'devices',
            'description' => 'Heartbeat viejo',
            'created_at' => now()->subDays(150),
            'updated_at' => now()->subDays(150),
        ]);

        $recentLogId = DB::table('audit_logs')->insertGetId([
            'event' => 'users.updated',
            'action' => 'update',
            'entity' => 'users',
            'description' => 'Cambio reciente',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $dryRunResponse = $this->actingAs($user)
            ->postJson('/settings/audit-logs/purge', [
                'mode' => 'before_date',
                'before_date' => now()->subDays(90)->format('Y-m-d'),
                'dry_run' => true,
            ]);

        $dryRunResponse->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.total_detected', 1)
            ->assertJsonPath('data.total_deleted', 0);

        $executeResponse = $this->actingAs($user)
            ->postJson('/settings/audit-logs/purge', [
                'mode' => 'before_date',
                'before_date' => now()->subDays(90)->format('Y-m-d'),
                'dry_run' => false,
            ]);

        $executeResponse->assertOk()
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.total_detected', 1)
            ->assertJsonPath('data.total_deleted', 1);

        $this->assertDatabaseMissing('audit_logs', ['id' => $oldLogId]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recentLogId]);
        $this->assertDatabaseHas('audit_cleanup_runs', [
            'trigger_source' => 'manual',
            'status' => 'completed',
            'deleted_records' => 1,
        ]);
    }

    public function test_audit_logs_purge_before_date_uses_operations_timezone_boundaries(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('operations.timezone', 'America/Mexico_City');

        $user = $this->createUserWithAuditPermissions(['manage']);

        $deletedLogId = DB::table('audit_logs')->insertGetId([
            'event' => 'users.updated',
            'action' => 'update',
            'entity' => 'users',
            'description' => 'Registro antes del corte local',
            'created_at' => '2026-06-01 05:59:59',
            'updated_at' => '2026-06-01 05:59:59',
        ]);

        $keptLogId = DB::table('audit_logs')->insertGetId([
            'event' => 'users.updated',
            'action' => 'update',
            'entity' => 'users',
            'description' => 'Registro en el inicio del dia local',
            'created_at' => '2026-06-01 06:00:00',
            'updated_at' => '2026-06-01 06:00:00',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/settings/audit-logs/purge', [
                'mode' => 'before_date',
                'before_date' => '2026-06-01',
                'dry_run' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.total_detected', 1)
            ->assertJsonPath('data.total_deleted', 1);

        $this->assertDatabaseMissing('audit_logs', ['id' => $deletedLogId]);
        $this->assertDatabaseHas('audit_logs', ['id' => $keptLogId]);
    }

    private function createUserWithAuditPermissions(array $actions): User
    {
        $user = User::factory()->create();

        $role = Role::query()->create([
            'name' => 'Audit '.uniqid(),
            'description' => 'Role for audit tests',
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
}
