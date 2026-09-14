<?php

namespace Tests\Feature\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportActivityService;
use App\Services\Support\SupportActivityWebQueries;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportTicketService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportActivityWebTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08T18:00:00Z'));
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        foreach (['attendance_logs', 'vending_attendance_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->travelBack();
        parent::tearDown();
    }

    private function technician(array $permissions = ['view', 'assign', 'resolve', 'configure', 'verify', 'report']): User
    {
        $employee = $this->employee(['employee_number' => 'FIELD-'.Str::uuid(), 'source' => 'FORTIA', 'source_external_id' => (string) Str::uuid()]);
        $user = User::factory()->create(['estatus' => true]);
        $user->employee()->associate($employee);
        $user->save();
        $role = Role::query()->create(['name' => 'Field Web '.Str::uuid()]);
        foreach ($permissions as $action) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['module' => 'support', 'action' => $action], ['name' => 'support.'.$action]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function scenario(): array
    {
        $user = $this->technician();
        $machine = $this->machine('WEB-001');
        $assignment = $this->assignment($machine, $user->employee, ['attendance_allowed' => false, 'maintenance_allowed' => true]);
        $this->actingAs($user);
        $input = ['client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id,
            'employee_id' => $user->employee_id, 'activity_type' => 'MAINTENANCE', 'title' => 'Mantenimiento sintético'];
        $activity = app(SupportActivityService::class)->create($input);

        return compact('user', 'machine', 'assignment', 'input', 'activity');
    }

    public function test_inertia_list_detail_and_original_json_contract(): void
    {
        $s = $this->scenario();
        $this->get('/support/activities')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Support/Activities/Index')->has('activities.data', 1)->where('canCreate', true)
            ->where('activities.data.0.folio', 'ACT-000001')->where('activities.data.0.employee.has_active_account', true));
        $this->get('/support/activities/'.$s['activity']->uuid)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Support/Activities/Show')->has('events', 2)->where('events.0.kind', 'created')
            ->where('events.1.kind', 'assigned')->where('canCancel', true)->where('activity.snapshot.result', 'NOT_EVALUATED')
            ->missing('activity.started_latitude')->missing('activity.employee.source_external_id')->missing('activity.employee.email'));
        $this->getJson('/support/activities')->assertOk()->assertJsonPath('data.0.uuid', $s['activity']->uuid)->assertJsonMissingPath('data.0.employee');
        $this->getJson('/support/activities/'.$s['activity']->uuid)->assertOk()->assertJsonPath('activity.status', 'ASSIGNED')->assertJsonMissingPath('activity.snapshot');
    }

    public function test_all_filters_folio_and_cdmx_day_boundaries(): void
    {
        $s = $this->scenario();
        DB::table('vending_support_activities')->where('id', $s['activity']->id)->update(['created_at' => '2026-09-09 05:59:59']);
        foreach (['search' => 'ACT-000001', 'status' => 'ASSIGNED', 'activity_type' => 'MAINTENANCE', 'vending_machine_id' => $s['machine']->id,
            'employee_id' => $s['user']->employee_id, 'from' => '2026-09-08', 'to' => '2026-09-08', 'has_ticket' => 'no'] as $key => $value) {
            $this->get('/support/activities?'.http_build_query([$key => $value]))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->where('activities.total', 1));
        }
        foreach ([['search' => 'WEB-001'], ['from' => '2026-09-09'], ['status' => 'COMPLETED'], ['has_ticket' => 'yes']] as $filter) {
            $this->get('/support/activities?'.http_build_query($filter))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->where('activities.total', isset($filter['search']) ? 1 : 0));
        }
        $this->getJson('/support/activities/options?kind=employees&page=0')->assertUnprocessable();
        $this->getJson('/support/activities/options?kind=employees&purpose=create')->assertUnprocessable();
    }

    public function test_web_create_assigns_employee_without_account_and_replays_without_side_effects(): void
    {
        $s = $this->scenario();
        $target = $this->employee(['employee_number' => 'NO-ACCOUNT', 'source' => 'FORTIA', 'source_external_id' => 'fixture-external']);
        $assignment = $this->assignment($s['machine'], $target, ['attendance_allowed' => false, 'maintenance_allowed' => true]);
        $input = array_replace($s['input'], ['employee_id' => $target->id, 'client_operation_uuid' => (string) Str::uuid()]);
        $users = User::count();
        $this->get('/support/activities/create')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Support/Activities/Create')->has('types', 9)->missing('employees')->missing('machines'));
        $this->post('/support/activities', $input)->assertStatus(303);
        $this->post('/support/activities', $input)->assertStatus(303);
        $this->post('/support/activities', array_replace($input, ['title' => 'Different intent']))->assertSessionHasErrors('activity');
        $this->assertDatabaseCount('vending_support_activities', 2);
        $this->assertDatabaseCount('vending_support_activity_events', 4);
        $this->assertSame($users, User::count());
        $this->assertNull($target->fresh()->user);
        $this->assertFalse($assignment->fresh()->attendance_allowed);
        $this->assertTrue($assignment->fresh()->maintenance_allowed);
    }

    public function test_unlinked_inactive_and_missing_schema_fail_closed_without_breaking_listing(): void
    {
        $s = $this->scenario();
        DB::table('users')->where('id', $s['user']->id)->update(['employee_id' => null]);
        $this->get('/support/activities')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canCreate', false)->where('activities', null)->where('unavailable', fn ($v) => str_contains($v, 'identidad laboral')));
        $this->getJson('/support/activities/options?kind=machines')->assertForbidden();
        $this->post('/support/activities', $s['input'])->assertForbidden();
        $this->getJson('/support/activities/summary?vending_machine_id='.$s['machine']->id)->assertOk()->assertExactJson(['available' => false]);
        Schema::shouldReceive('hasColumn')->with('users', 'employee_id')->once()->andReturn(false);
        $this->assertStringContainsString('entorno', app(SupportActivityWebQueries::class)->unavailableReason());
    }

    public function test_rbac_idor_read_mutation_and_selectors_preserve_rows(): void
    {
        $s = $this->scenario();
        $other = $this->technician(['view', 'view_all', 'assign', 'resolve']);
        $otherMachine = $this->machine();
        $this->assignment($otherMachine, $other->employee, ['maintenance_allowed' => true]);
        $before = $s['activity']->getRawOriginal();
        $this->actingAs($other);
        $this->get('/support/activities')->assertOk()->assertInertia(fn (Assert $p) => $p->where('activities.total', 0));
        $this->get('/support/activities/'.$s['activity']->uuid)->assertNotFound();
        $this->post('/support/activities/'.$s['activity']->uuid.'/cancel', ['cancellation_reason' => 'Intento cruzado'])->assertForbidden();
        $this->getJson('/support/activities/options?kind=employees&purpose=create&activity_type=MAINTENANCE&vending_machine_id='.$s['machine']->id)->assertForbidden();
        $this->post('/support/activities', array_replace($s['input'], ['client_operation_uuid' => (string) Str::uuid()]))->assertForbidden();
        $this->getJson('/support/activities/options?kind=employees')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/support/activities/summary?vending_machine_id='.$s['machine']->id)->assertOk()->assertJsonCount(0, 'activities');
        $this->assertSame($before, $s['activity']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('vending_support_activities', 1);
        $this->assertDatabaseCount('vending_support_activity_events', 2);
        $noView = $this->technician(['resolve']);
        $this->actingAs($noView)->get('/support/activities')->assertForbidden();
        $this->getJson('/support/activities/options?kind=machines')->assertForbidden();
        $this->get('/support/activities/create')->assertForbidden();
    }

    public function test_viewer_has_no_create_and_cannot_read_colleague_without_assign_permission(): void
    {
        $s = $this->scenario();
        $viewer = $this->technician(['view', 'view_all']);
        $this->assignment($s['machine'], $viewer->employee, ['maintenance_allowed' => true]);
        $this->actingAs($viewer)->get('/support/activities')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canCreate', false)->where('activities.total', 0));
        $this->get('/support/activities/'.$s['activity']->uuid)->assertNotFound();
        $this->get('/support/activities/create')->assertForbidden();
        $this->post('/support/activities', $s['input'])->assertForbidden();
    }

    public function test_employee_and_machine_options_are_eligible_scoped_and_paginated(): void
    {
        $s = $this->scenario();
        foreach (range(1, 24) as $i) {
            $employee = $this->employee(['employee_number' => 'OPTION-'.$i, 'source' => 'FORTIA', 'source_external_id' => 'fixture-'.$i]);
            $this->assignment($s['machine'], $employee, ['maintenance_allowed' => true]);
            $machine = $this->machine('OPTION-'.$i);
            $this->assignment($machine, $s['user']->employee);
        }
        foreach ([['source' => 'MANUAL'], ['status' => 'I'], ['source_external_id' => ' ']] as $override) {
            $employee = $this->employee(array_replace(['employee_number' => 'EXCLUDED-'.Str::uuid(), 'source' => 'FORTIA', 'source_external_id' => (string) Str::uuid()], $override));
            $this->assignment($s['machine'], $employee, ['maintenance_allowed' => true]);
        }
        $base = '/support/activities/options?purpose=create&kind=employees&activity_type=MAINTENANCE&vending_machine_id='.$s['machine']->id;
        $this->getJson($base)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('has_more', true)->assertJsonMissingPath('data.0.source_external_id')->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.fingerprint_status')->assertJsonMissingPath('data.0.resolved_check_scope');
        $this->getJson($base.'&page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('has_more', false);
        $this->getJson($base.'&search=OPTION-24')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.has_account', false);
        $this->getJson('/support/activities/options?purpose=create&kind=machines')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('has_more', true);
        $this->getJson('/support/activities/options?purpose=create&kind=machines&page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/support/activities/options?purpose=create&kind=machines&search=OPTION-24')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reserved_draft_and_expired_assignments_are_not_selectable(): void
    {
        $s = $this->scenario();
        $s['machine']->update(['status' => 'DRAFT']);
        $this->getJson('/support/activities/options?kind=machines&purpose=create')->assertOk()->assertJsonCount(0, 'data');
        $s['machine']->update(['status' => 'ACTIVE', 'source' => 'SYBI', 'machine_code' => '7']);
        $this->getJson('/support/activities/options?kind=machines&purpose=create')->assertOk()->assertJsonCount(0, 'data');
        $this->post('/support/activities', array_replace($s['input'], ['client_operation_uuid' => (string) Str::uuid()]))->assertForbidden();
        $s['machine']->update(['source' => 'LOCAL', 'machine_code' => 'WEB-001']);
        $s['assignment']->update(['valid_until' => now()->subMinute()]);
        $this->getJson('/support/activities/options?kind=machines&purpose=create')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cancel_web_has_actor_history_and_conflict_without_implicit_reassignment(): void
    {
        $s = $this->scenario();
        $path = '/support/activities/'.$s['activity']->uuid.'/cancel';
        $this->post($path, ['cancellation_reason' => 'Cancelación sintética'])->assertStatus(303);
        $this->assertDatabaseHas('vending_support_activity_events', ['activity_id' => $s['activity']->id, 'kind' => 'cancelled', 'user_id' => $s['user']->id]);
        $this->from('/support/activities/'.$s['activity']->uuid)->post($path, ['cancellation_reason' => 'Reintento'])->assertSessionHasErrors('activity');
        $this->get('/support/activities/'.$s['activity']->uuid)->assertOk()->assertInertia(fn (Assert $p) => $p->where('canCancel', false)->has('events', 3));
        $this->post($path, ['cancellation_reason' => 'Intento', 'employee_id' => 999])->assertSessionHasErrors('employee_id');
        $this->assertDatabaseCount('vending_support_activity_events', 3);
    }

    public function test_detail_uses_stored_snapshot_and_chronological_domain_events_only(): void
    {
        $s = $this->scenario();
        $geofence = $this->geofence($s['machine']);
        app(SupportActivityService::class)->start($s['activity']->uuid, ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5, 'captured_at' => now()->toISOString()]);
        $this->geofence($s['machine'], ['center_latitude' => 20, 'radius_m' => 500]);
        $this->get('/support/activities/'.$s['activity']->uuid)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('activity.snapshot.result', 'INSIDE')->where('activity.snapshot.version', $geofence->version)
            ->where('activity.snapshot.accuracy_m', 5)->where('activity.snapshot.distance_m', 0)
            ->has('events', 3)->where('events.2.kind', 'started')->missing('activity.snapshot.latitude')->missing('activity.snapshot.center_latitude'));
    }

    public function test_ticket_relation_is_one_to_many_and_independently_scoped(): void
    {
        $s = $this->scenario();
        $ticket = app(SupportTicketService::class)->create(SupportActor::user($s['user']), [
            'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $s['machine']->id,
            'category' => 'OTHER', 'title' => 'Ticket sintético', 'description' => 'Sólo fixture',
        ])['ticket'];
        foreach (range(1, 2) as $i) {
            app(SupportActivityService::class)->create(array_replace($s['input'], ['client_operation_uuid' => (string) Str::uuid(), 'support_ticket_uuid' => $ticket['uuid']]));
        }
        $row = SupportTicket::where('uuid', $ticket['uuid'])->firstOrFail();
        $before = $row->getRawOriginal();
        $this->getJson('/support/activities/summary?ticket_uuid='.$ticket['uuid'])->assertOk()->assertJsonCount(2, 'activities')->assertJsonPath('activities.0.ticket.folio', $row->folio);
        $this->getJson('/support/activities/options?kind=tickets&purpose=create&vending_machine_id='.$s['machine']->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/support/activities/options?kind=tickets&purpose=create&vending_machine_id='.$s['machine']->id.'&search='.$row->folio)->assertOk()->assertJsonCount(1, 'data');
        $other = $this->technician();
        $this->assignment($s['machine'], $other->employee, ['maintenance_allowed' => true]);
        $this->actingAs($other);
        $this->getJson('/support/activities/summary?ticket_uuid='.$ticket['uuid'])->assertNotFound();
        $this->getJson('/support/activities/options?kind=tickets&purpose=create&vending_machine_id='.$s['machine']->id)->assertOk()->assertJsonCount(0, 'data');
        $this->get('/support/activities')->assertOk()->assertInertia(fn (Assert $p) => $p->where('activities.data.0.ticket', null));
        $this->post('/support/activities', array_replace($s['input'], ['client_operation_uuid' => (string) Str::uuid(), 'support_ticket_uuid' => $ticket['uuid']]))->assertNotFound();
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertDatabaseCount('vending_support_activities', 3);
    }

    public function test_list_query_count_is_bounded_without_n_plus_one_and_summary_is_limited(): void
    {
        $s = $this->scenario();
        $measure = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(SupportActivityWebQueries::class)->listing([]);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $one = $measure();
        foreach (range(1, 29) as $i) {
            app(SupportActivityService::class)->create(array_replace($s['input'], ['client_operation_uuid' => (string) Str::uuid()]));
        }
        $many = $measure();
        $this->assertLessThanOrEqual($one, $many);
        $this->assertLessThanOrEqual(15, $many);
        fwrite(STDOUT, "\nWEB_ACTIVITY_QUERIES one={$one} page25={$many}\n");
        $this->get('/support/activities')->assertOk()->assertInertia(fn (Assert $p) => $p->has('activities.data', 25)->where('activities.total', 30));
        $this->getJson('/support/activities/summary?vending_machine_id='.$s['machine']->id)->assertOk()->assertJsonCount(5, 'activities');
    }

    public function test_scale_2507_employees_and_1000_machines_keeps_options_bounded(): void
    {
        $s = $this->scenario();
        $employees = [];
        $assignments = [];
        $machines = [];
        for ($i = 2; $i <= 2507; $i++) {
            $employees[] = ['id' => $i, 'employee_number' => 'SCALE-E-'.$i, 'full_name' => 'Persona sintética '.$i,
                'source' => 'FORTIA', 'source_external_id' => 'scale-'.$i, 'status' => 'A'];
            $assignments[] = ['uuid' => (string) Str::uuid(), 'employee_id' => $i, 'vending_machine_id' => $s['machine']->id,
                'assignment_type' => 'PRIMARY', 'source' => 'TEST', 'status' => 'ACTIVE', 'maintenance_allowed' => true,
                'valid_from' => now()->subHour(), 'valid_until' => null];
        }
        foreach (array_chunk($employees, 250) as $chunk) {
            DB::table('employees')->insert($chunk);
        }
        foreach (array_chunk($assignments, 250) as $chunk) {
            DB::table('employee_machine_assignments')->insert($chunk);
        }
        $assignments = [];
        for ($i = 2; $i <= 1000; $i++) {
            $machines[] = ['id' => $i, 'uuid' => (string) Str::uuid(), 'machine_code' => 'SCALE-M-'.$i, 'source' => 'LOCAL', 'status' => 'ACTIVE'];
            $assignments[] = ['uuid' => (string) Str::uuid(), 'employee_id' => $s['user']->employee_id, 'vending_machine_id' => $i,
                'assignment_type' => 'PRIMARY', 'source' => 'TEST', 'status' => 'ACTIVE', 'maintenance_allowed' => true,
                'valid_from' => now()->subHour(), 'valid_until' => null];
        }
        foreach (array_chunk($machines, 250) as $chunk) {
            DB::table('vending_machines')->insert($chunk);
        }
        foreach (array_chunk($assignments, 250) as $chunk) {
            DB::table('employee_machine_assignments')->insert($chunk);
        }
        $this->assertDatabaseCount('employees', 2507);
        $this->assertDatabaseCount('vending_machines', 1000);
        $base = '/support/activities/options?kind=employees&purpose=create&activity_type=MAINTENANCE&vending_machine_id='.$s['machine']->id;
        $this->getJson($base)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('has_more', true);
        $this->getJson($base.'&page=126')->assertOk()->assertJsonCount(7, 'data')->assertJsonPath('has_more', false);
        $this->getJson($base.'&search=SCALE-E-2507')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/support/activities/options?kind=machines&purpose=create&page=50')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('has_more', false);
        $this->getJson('/support/activities/options?kind=machines&purpose=create&search=SCALE-M-1000')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('users', 1);
    }
}
