<?php

namespace Tests\Feature\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportTicketService;
use Database\Seeders\SupportPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportWebTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function pilot(string $role): User
    {
        $model = Role::query()->firstOrCreate(['name' => 'Vending Pilot '.$role]);
        $user = User::factory()->create(['estatus' => true]);
        $user->roles()->attach($model);
        $this->seed(SupportPermissionsSeeder::class);

        return $user;
    }

    public function test_support_permission_seeding_is_additive_no_accounts_or_existing_roles_are_changed(): void
    {
        $operator = $this->pilot('Operator');
        $before = $operator->fresh()->getRawOriginal();
        $legacy = Permission::query()->firstOrCreate(['module' => 'employees', 'action' => 'view'], ['name' => 'Employees view']);
        $role = $operator->roles()->first();
        $role->permissions()->attach($legacy);
        $roles = Role::count();
        $users = User::count();
        $this->seed(SupportPermissionsSeeder::class);
        $this->seed(SupportPermissionsSeeder::class);
        $this->assertSame($roles, Role::count());
        $this->assertSame($users, User::count());
        $this->assertTrue($before === $operator->fresh()->getRawOriginal(), 'Existing account fields must not change.');
        $this->assertTrue($operator->fresh()->hasPermission('employees', 'view'));
        $this->assertTrue($operator->fresh()->hasPermission('support', 'report'));
        $this->assertFalse($operator->fresh()->hasPermission('support', 'view_all'));
        $this->assertFalse($operator->fresh()->hasPermission('support', 'resolve'));
    }

    public function test_operator_can_report_but_only_view_and_comment_own_tickets(): void
    {
        $operator = $this->pilot('Operator');
        $other = $this->pilot('Operator');
        $machine = $this->machine();
        $payload = ['client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id, 'category' => 'SCREEN', 'title' => 'Pantalla de prueba', 'description' => 'Reporte controlado'];
        $this->actingAs($operator)->post('/support/tickets', $payload)->assertRedirect();
        $ticket = SupportTicket::firstOrFail();
        $this->actingAs($operator)->get('/support/tickets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Support/Index')->has('tickets', 1)->where('stats.open', 1));
        $this->actingAs($other)->get('/support/tickets')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tickets', 0)->where('stats.open', 0));
        $this->actingAs($other)->get('/support/tickets/'.$ticket->uuid)->assertNotFound();
        $this->actingAs($other)->post('/support/tickets/'.$ticket->uuid.'/comments', ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'No autorizado'])->assertNotFound();
        $this->actingAs($operator)->post('/support/tickets/'.$ticket->uuid.'/comments', ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'Información adicional'])->assertRedirect();
        $this->actingAs($operator)->post('/support/tickets/'.$ticket->uuid.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'IN_PROGRESS'])->assertForbidden();
        $this->assertDatabaseCount('support_ticket_events', 2);
    }

    public function test_viewer_can_read_but_cannot_mutate_and_support_can_assign_resolve_but_not_close(): void
    {
        $admin = $this->pilot('Admin');
        $viewer = $this->pilot('Viewer');
        $support = $this->pilot('Support');
        $ticket = app(SupportTicketService::class)->create(SupportActor::user($admin), [
            'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $this->machine()->id, 'category' => 'OTHER', 'title' => 'Prueba', 'description' => 'Controlado',
        ])['ticket']['uuid'];
        $this->actingAs($viewer)->get('/support/tickets/'.$ticket)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Support/Show'));
        $this->actingAs($viewer)->post('/support/tickets/'.$ticket.'/assign', ['client_operation_uuid' => (string) Str::uuid(), 'assignee_id' => $support->id])->assertForbidden();
        $this->actingAs($support)->post('/support/tickets/'.$ticket.'/assign', ['client_operation_uuid' => (string) Str::uuid(), 'assignee_id' => $support->id])->assertRedirect();
        $this->actingAs($support)->post('/support/tickets/'.$ticket.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'RESOLVED', 'resolution' => 'Atendido'])->assertRedirect();
        $this->actingAs($support)->post('/support/tickets/'.$ticket.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'CLOSED'])->assertForbidden();
        $this->actingAs($admin)->post('/support/tickets/'.$ticket.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'CLOSED'])->assertRedirect();
        $this->assertDatabaseHas('support_tickets', ['uuid' => $ticket, 'status' => 'CLOSED', 'assignee_id' => $support->id]);
    }

    public function test_no_settings_manage_bypass_and_inactive_account_denied(): void
    {
        $user = User::factory()->create(['estatus' => true]);
        $role = Role::create(['name' => 'Legacy settings']);
        $p = Permission::firstOrCreate(['module' => 'settings', 'action' => 'manage'], ['name' => 'Settings']);
        $role->permissions()->attach($p);
        $user->roles()->attach($role);
        $this->actingAs($user)->get('/support/tickets')->assertForbidden();
        $admin = $this->pilot('Admin');
        $admin->update(['estatus' => false]);
        $this->actingAs($admin)->get('/support/tickets')->assertForbidden();
    }

    public function test_filters_pagination_and_dashboard_counts_use_full_authorized_scope(): void
    {
        $admin = $this->pilot('Admin');
        $machine = $this->machine();
        for ($i = 0; $i < 4; $i++) {
            app(SupportTicketService::class)->create(SupportActor::user($admin), [
                'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id,
                'category' => 'OTHER', 'title' => 'Reporte '.$i, 'description' => 'Prueba',
                'severity' => $i === 0 ? 'HIGH' : 'MEDIUM',
            ]);
        }
        $this->actingAs($admin)->get('/support/tickets?limit=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tickets', 2)->where('pagination.total', 4)->where('stats.open', 4)->where('stats.high_critical', 1));
        $this->actingAs($admin)->get('/support/tickets?severity=HIGH')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tickets', 1)->where('pagination.total', 1));
    }
}
