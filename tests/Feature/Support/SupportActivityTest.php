<?php

namespace Tests\Feature\Support;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VendingSupportActivity;
use App\Services\Support\SupportActivityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportActivityTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08T18:00:00Z'));
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        foreach (['attendance_logs', 'vending_attendance_events', 'attendance_dailies', 'attendances_raw'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->travelBack();
        parent::tearDown();
    }

    private function technician(array $permissions = ['view', 'assign', 'resolve', 'configure', 'verify']): User
    {
        $employee = $this->employee([
            'employee_number' => 'FIELD-'.Str::uuid(), 'source' => 'FORTIA',
            'source_external_id' => (string) Str::uuid(), 'status' => 'A',
        ]);
        $user = User::factory()->create(['estatus' => true]);
        $user->employee()->associate($employee);
        $user->save();
        $role = Role::query()->create(['name' => 'Field '.Str::uuid()]);
        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['module' => 'support', 'action' => $permission], ['name' => 'support.'.$permission])->id);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function scenario(): array
    {
        $user = $this->technician();
        $machine = $this->machine();
        $assignment = $this->assignment($machine, $user->employee, ['attendance_allowed' => false, 'maintenance_allowed' => true]);
        $geofence = $this->geofence($machine);
        $this->actingAs($user);
        $activity = app(SupportActivityService::class)->create([
            'vending_machine_id' => $machine->id, 'employee_id' => $user->employee_id,
            'client_operation_uuid' => (string) Str::uuid(),
            'activity_type' => 'MAINTENANCE', 'title' => 'Synthetic activity', 'description' => 'Fixture only',
        ]);

        return compact('user', 'machine', 'assignment', 'geofence', 'activity');
    }

    private function gps(array $overrides = []): array
    {
        return array_replace(['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5, 'captured_at' => now('UTC')->toIso8601String()], $overrides);
    }

    public function test_http_start_complete_and_safe_presentation(): void
    {
        $s = $this->scenario();
        $path = '/support/activities/'.$s['activity']->uuid;
        $this->getJson($path)->assertOk()->assertJsonPath('activity.status', 'ASSIGNED');
        $this->postJson($path.'/start', $this->gps())->assertOk()->assertJsonPath('activity.status', 'IN_PROGRESS');
        $this->postJson($path.'/complete')->assertOk()->assertJsonPath('activity.status', 'COMPLETED');
        $body = $this->getJson($path)->assertOk()->getContent();
        foreach (['started_latitude', 'started_longitude', 'started_accuracy_m', 'password', 'source_external_id'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body);
        }
        $this->assertDatabaseHas('vending_support_activity_events', ['kind' => 'started', 'user_id' => $s['user']->id, 'employee_id' => $s['user']->employee_id]);
    }

    public function test_ticket_nullable_and_one_to_many_does_not_change_ticket_workflow(): void
    {
        $s = $this->scenario();
        $this->assertNull($s['activity']->support_ticket_id);
        $s['user']->roles->first()->permissions()->attach(Permission::query()->firstOrCreate(
            ['module' => 'support', 'action' => 'report'], ['name' => 'support.report']));
        $s['user']->unsetRelation('roles');
        $ticket = app(\App\Services\Support\SupportTicketService::class)->create(
            \App\Services\Support\SupportActor::user($s['user']), [
                'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $s['machine']->id,
                'category' => 'OTHER', 'title' => 'Fixture ticket', 'description' => 'Fixture only',
            ])['ticket'];
        $row = \App\Models\SupportTicket::query()->where('uuid', $ticket['uuid'])->firstOrFail();
        $before = $row->getRawOriginal();
        foreach (range(1, 2) as $unused) {
            $activity = app(SupportActivityService::class)->create([
                'vending_machine_id' => $s['machine']->id, 'employee_id' => $s['user']->employee_id,
                'client_operation_uuid' => (string) Str::uuid(),
                'activity_type' => 'MAINTENANCE', 'title' => 'Scheduled maintenance',
                'support_ticket_uuid' => $row->uuid,
            ]);
            app(SupportActivityService::class)->start($activity->uuid, $this->gps());
            app(SupportActivityService::class)->complete($activity->uuid);
        }
        $this->assertSame(2, $row->activities()->count());
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $otherMachine = $this->machine();
        $this->assignment($otherMachine, $s['user']->employee, ['maintenance_allowed' => true]);
        $this->postJson('/support/activities', [
            'vending_machine_id' => $otherMachine->id, 'employee_id' => $s['user']->employee_id,
            'client_operation_uuid' => (string) Str::uuid(),
            'activity_type' => 'MAINTENANCE', 'title' => 'Wrong machine', 'support_ticket_uuid' => $row->uuid,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('vending_support_activities', 3);
    }

    public function test_outside_uncertain_and_missing_geofence_deny_without_mutation(): void
    {
        $s = $this->scenario();
        $before = $s['activity']->fresh()->getRawOriginal();
        foreach ([
            [$this->gps(['latitude' => 20]), 'OUTSIDE'],
            [$this->gps(['accuracy_m' => 100]), 'UNCERTAIN'],
        ] as [$gps, $result]) {
            $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $gps)
                ->assertUnprocessable()->assertJsonPath('geofence_result', $result);
            $this->assertSame($before, $s['activity']->fresh()->getRawOriginal());
        }
        app(\App\Services\Vending\MachineGeofenceService::class)->deactivate($s['geofence']);
        $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps())
            ->assertUnprocessable()->assertJsonPath('geofence_result', 'NOT_EVALUATED');
        $this->assertDatabaseCount('vending_support_activity_events', 2);
    }

    public function test_latest_active_geofence_not_planning_version_is_snapshotted(): void
    {
        $s = $this->scenario();
        $new = $this->geofence($s['machine'], ['radius_m' => 75]);
        $result = app(SupportActivityService::class)->start($s['activity']->uuid, $this->gps());
        $this->assertSame($new->id, $result->machine_geofence_id);
        $this->assertSame($new->version, $result->geofence_version);
        $this->assertNotEquals($s['geofence']->id, $result->machine_geofence_id);
        $before = $result->getRawOriginal();
        $this->geofence($s['machine'], ['radius_m' => 100]);
        $this->assertSame($before, $result->fresh()->getRawOriginal());
    }

    public function test_future_and_expired_geofences_do_not_authorize_start(): void
    {
        $s = $this->scenario();
        foreach ([
            ['valid_from' => now()->addHour(), 'valid_until' => null],
            ['valid_from' => now()->subDay(), 'valid_until' => now()->subSecond()],
        ] as $window) {
            DB::table('machine_geofences')->where('id', $s['geofence']->id)->update($window);
            $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps())
                ->assertUnprocessable()->assertJsonPath('geofence_result', 'NOT_EVALUATED');
        }
    }

    public function test_invalid_or_stale_gps_cannot_start(): void
    {
        $s = $this->scenario();
        foreach ([
            ['latitude' => 0, 'longitude' => 0], ['latitude' => 91], ['longitude' => -181],
            ['accuracy_m' => -1], ['accuracy_m' => null],
            ['captured_at' => now()->subMinutes(6)->toIso8601String()],
            ['captured_at' => now()->addMinute()->toIso8601String()],
        ] as $invalid) {
            $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps($invalid))->assertUnprocessable();
        }
        $this->assertSame('ASSIGNED', $s['activity']->fresh()->status->value);
        $this->assertDatabaseCount('vending_support_activity_events', 2);
    }

    public function test_inactive_user_employee_and_missing_identity_are_denied(): void
    {
        $s = $this->scenario();
        $path = '/support/activities/'.$s['activity']->uuid.'/start';
        DB::table('employees')->where('id', $s['user']->employee_id)->update(['status' => 'B']);
        $this->postJson($path, $this->gps())->assertForbidden();
        DB::table('employees')->where('id', $s['user']->employee_id)->update(['status' => 'A']);
        DB::table('users')->where('id', $s['user']->id)->update(['estatus' => false]);
        $this->postJson($path, $this->gps())->assertForbidden();
        DB::table('users')->where('id', $s['user']->id)->update(['estatus' => true, 'employee_id' => null]);
        $this->postJson($path, $this->gps())->assertForbidden();
        $this->assertNotNull(Employee::find($s['activity']->employee_id));
        $this->assertSame('ASSIGNED', $s['activity']->fresh()->status->value);
    }

    public function test_machine_assignment_capability_and_rbac_are_independent_gates(): void
    {
        $s = $this->scenario();
        $path = '/support/activities/'.$s['activity']->uuid.'/start';
        DB::table('vending_machines')->where('id', $s['machine']->id)->update(['status' => 'INACTIVE']);
        $this->postJson($path, $this->gps())->assertForbidden();
        DB::table('vending_machines')->where('id', $s['machine']->id)->update(['status' => 'ACTIVE']);
        foreach ([
            ['maintenance_allowed' => false],
            ['maintenance_allowed' => true, 'status' => 'REVOKED'],
            ['status' => 'ACTIVE', 'valid_until' => now()->subSecond()],
            ['valid_until' => null, 'valid_from' => now()->addHour()],
        ] as $changes) {
            DB::table('employee_machine_assignments')->where('id', $s['assignment']->id)->update($changes);
            $this->postJson($path, $this->gps())->assertForbidden();
        }
        DB::table('employee_machine_assignments')->where('id', $s['assignment']->id)
            ->update(['valid_from' => now()->subMinute()]);
        $s['user']->roles()->detach();
        $this->postJson($path, $this->gps())->assertForbidden();
        $this->assertSame('ASSIGNED', $s['activity']->fresh()->status->value);
    }

    public function test_maintenance_never_grants_attendance_or_unrelated_activity_capability(): void
    {
        $s = $this->scenario();
        $authorization = app(\App\Services\Vending\MachineAuthorizationService::class);
        $this->assertTrue($authorization->canMaintain($s['user']->employee, $s['machine']));
        $this->assertFalse($authorization->canAttend($s['user']->employee, $s['machine']));
        $this->assertFalse($authorization->canEnroll($s['user']->employee, $s['machine']));
        $activity = app(SupportActivityService::class)->create([
            'vending_machine_id' => $s['machine']->id, 'employee_id' => $s['user']->employee_id,
            'client_operation_uuid' => (string) Str::uuid(),
            'activity_type' => 'CONFIGURATION', 'title' => 'Configuration fixture',
        ]);
        $permission = Permission::where('module', 'support')->where('action', 'configure')->firstOrFail();
        $s['user']->roles->first()->permissions()->detach($permission);
        $this->postJson('/support/activities/'.$activity->uuid.'/start', $this->gps())->assertForbidden();
        $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps())->assertOk();
        $this->assertFalse($authorization->canAttend($s['user']->employee, $s['machine']));
    }

    public function test_non_maintenance_uses_explicit_rbac_and_assignment_not_attendance_flags(): void
    {
        $s = $this->scenario();
        DB::table('employee_machine_assignments')->where('id', $s['assignment']->id)
            ->update(['maintenance_allowed' => false, 'attendance_allowed' => false, 'enrollment_allowed' => false]);
        foreach (['INSTALLATION', 'CONFIGURATION', 'DIAGNOSTIC', 'CONNECTIVITY', 'SOFTWARE_UPDATE'] as $type) {
            $row = app(SupportActivityService::class)->create([
                'vending_machine_id' => $s['machine']->id, 'employee_id' => $s['user']->employee_id,
                'client_operation_uuid' => (string) Str::uuid(),
                'activity_type' => $type, 'title' => 'Fixture '.$type,
            ]);
            $this->assertTrue($row->requires_physical_presence);
            $this->postJson('/support/activities/'.$row->uuid.'/start', $this->gps())->assertOk();
        }
    }

    public function test_replays_are_domain_conflicts_without_duplicate_events_or_audit(): void
    {
        $s = $this->scenario();
        $path = '/support/activities/'.$s['activity']->uuid;
        $this->postJson($path.'/complete')->assertConflict();
        $this->postJson($path.'/start', $this->gps())->assertOk();
        $started = $s['activity']->fresh()->getRawOriginal();
        $auditCount = DB::table('audit_logs')->where('event', 'like', 'support_activity.%')->count();
        $this->postJson($path.'/start', $this->gps())->assertConflict();
        $this->assertSame($started, $s['activity']->fresh()->getRawOriginal());
        $this->assertSame($auditCount, DB::table('audit_logs')->where('event', 'like', 'support_activity.%')->count());
        $this->postJson($path.'/complete')->assertOk();
        $completed = $s['activity']->fresh()->getRawOriginal();
        $this->postJson($path.'/complete')->assertConflict();
        $this->postJson($path.'/cancel', ['cancellation_reason' => 'Fixture'])->assertConflict();
        $this->assertSame($completed, $s['activity']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('vending_support_activity_events', 4);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'support_activity.started')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'support_activity.completed')->count());
    }

    public function test_cancellation_requires_reason_preserves_record_and_is_terminal(): void
    {
        foreach ([false, true] as $startFirst) {
            $s = $this->scenario();
            $path = '/support/activities/'.$s['activity']->uuid;
            if ($startFirst) {
                $this->postJson($path.'/start', $this->gps())->assertOk();
            }
            $this->postJson($path.'/cancel', ['cancellation_reason' => '  '])->assertUnprocessable();
            $this->postJson($path.'/cancel', ['cancellation_reason' => 'Fixture cancelled'])->assertOk()->assertJsonPath('activity.status', 'CANCELLED');
            $this->assertDatabaseHas('vending_support_activities', ['id' => $s['activity']->id, 'cancellation_reason' => 'Fixture cancelled']);
            $this->postJson($path.'/start', $this->gps())->assertConflict();
            $this->postJson($path.'/complete')->assertConflict();
            $this->postJson($path.'/cancel', ['cancellation_reason' => 'Again'])->assertConflict();
        }
    }

    public function test_other_user_cannot_execute_activity_and_out_of_scope_reads_are_hidden(): void
    {
        $s = $this->scenario();
        $other = $this->technician();
        $this->actingAs($other);
        $path = '/support/activities/'.$s['activity']->uuid;
        $this->getJson($path)->assertNotFound();
        $this->getJson('/support/activities')->assertOk()->assertJsonPath('total', 0);
        $this->postJson($path.'/start', $this->gps())->assertNotFound();
        $this->postJson($path.'/cancel', ['cancellation_reason' => 'Unauthorized'])->assertForbidden();
        $this->assignment($s['machine'], $other->employee, ['maintenance_allowed' => true]);
        $this->postJson($path.'/start', $this->gps())->assertNotFound();
        $this->assertSame('ASSIGNED', $s['activity']->fresh()->status->value);
    }

    public function test_unlinked_employee_can_be_planned_but_cannot_execute_using_another_account(): void
    {
        $s = $this->scenario();
        $employee = $this->employee(['employee_number' => 'NO-ACCOUNT', 'source' => 'FORTIA', 'source_external_id' => 'NO-ACCOUNT', 'status' => 'A']);
        $this->assignment($s['machine'], $employee, ['attendance_allowed' => false, 'maintenance_allowed' => true]);
        $row = app(SupportActivityService::class)->create([
            'vending_machine_id' => $s['machine']->id, 'employee_id' => $employee->id,
            'client_operation_uuid' => (string) Str::uuid(),
            'activity_type' => 'MAINTENANCE', 'title' => 'Planned without login',
        ]);
        $this->assertNull($employee->user);
        $this->postJson('/support/activities/'.$row->uuid.'/start', $this->gps())->assertNotFound();
        $this->assertSame(1, User::count());
    }

    public function test_client_cannot_override_identity_machine_policy_or_workflow(): void
    {
        $s = $this->scenario();
        $path = '/support/activities/'.$s['activity']->uuid.'/start';
        foreach ([
            ['employee_id' => 999], ['machine_id' => 999], ['vending_machine_id' => 999],
            ['requires_physical_presence' => false], ['geofence_result' => 'INSIDE'],
            ['status' => 'COMPLETED'], ['started_by_user_id' => 999],
        ] as $override) {
            $this->postJson($path, $this->gps($override))->assertUnprocessable();
        }
        $this->assertSame('ASSIGNED', $s['activity']->fresh()->status->value);
    }

    public function test_guest_and_device_identity_do_not_identify_a_human(): void
    {
        $s = $this->scenario();
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->getJson('/support/activities')->assertUnauthorized();
        $this->withHeaders(['X-Device-Id' => 'fixture-device', 'X-Signature' => 'fixture'])
            ->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps())->assertUnauthorized();
    }

    public function test_audit_minimization_and_transaction_roll_back_if_durable_event_fails(): void
    {
        $s = $this->scenario();
        // Simulate the durable unique audit constraint preventing an inconsistent transition.
        DB::table('vending_support_activity_events')->insert([
            'activity_id' => $s['activity']->id, 'kind' => 'started', 'user_id' => $s['user']->id,
            'employee_id' => $s['user']->employee_id, 'occurred_at' => now('UTC'),
        ]);
        try {
            app(SupportActivityService::class)->start($s['activity']->uuid, $this->gps());
            $this->fail('Expected unique event conflict.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame('ASSIGNED', $s['activity']->fresh()->status->value);
            $this->assertNull($s['activity']->fresh()->started_at);
        }
        $audit = DB::table('audit_logs')->where('event', 'like', 'support_activity.%')->get()->toJson();
        foreach (['19.4326', '-99.1332', 'accuracy_m', 'Fixture only', 'latitude', 'longitude'] as $private) {
            $this->assertStringNotContainsString($private, $audit);
        }
    }

    public function test_create_requires_assignment_permission_and_valid_target_scope(): void
    {
        $s = $this->scenario();
        $other = $this->technician(['view', 'resolve']);
        $this->assignment($s['machine'], $other->employee, ['maintenance_allowed' => true]);
        $data = ['vending_machine_id' => $s['machine']->id, 'employee_id' => $other->employee_id,
            'client_operation_uuid' => (string) Str::uuid(),
            'activity_type' => 'MAINTENANCE', 'title' => 'Fixture'];
        $this->actingAs($other)->postJson('/support/activities', $data)->assertForbidden();
        $this->actingAs($s['user']);
        $unassigned = $this->technician();
        $this->postJson('/support/activities', array_replace($data, ['employee_id' => $unassigned->employee_id]))->assertForbidden();
        $this->postJson('/support/activities', array_replace($data, ['requires_physical_presence' => false]))->assertUnprocessable();
        $this->postJson('/support/activities', $data)->assertCreated();
        $this->assertDatabaseCount('vending_support_activities', 2);
    }

    public function test_complete_rechecks_live_authorization_after_start(): void
    {
        $s = $this->scenario();
        $path = '/support/activities/'.$s['activity']->uuid;
        $this->postJson($path.'/start', $this->gps())->assertOk();
        DB::table('employee_machine_assignments')->where('id', $s['assignment']->id)->update(['maintenance_allowed' => false]);
        $this->postJson($path.'/complete')->assertForbidden();
        $this->assertSame('IN_PROGRESS', $s['activity']->fresh()->status->value);
        $this->assertDatabaseCount('vending_support_activity_events', 3);
    }

    public function test_reserved_sybi_machine_is_denied_even_if_fixture_is_active_and_assigned(): void
    {
        $s = $this->scenario();
        // Synthetic in-memory machine only, never the reserved operational row.
        DB::table('vending_machines')->where('id', $s['machine']->id)->update(['source' => 'SYBI', 'machine_code' => '7']);
        $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps())->assertForbidden();
        $this->assertDatabaseCount('vending_support_activity_events', 2);
    }

    public function test_all_types_are_physical_in_policy_v1_with_spanish_labels(): void
    {
        $types = \App\Enums\Support\SupportActivityType::cases();
        $this->assertCount(9, $types);
        foreach ($types as $type) {
            $this->assertTrue(app(\App\Services\Support\SupportActivityPresencePolicy::class)->requiresPhysicalPresence($type));
            $this->assertNotSame($type->value, $type->label());
        }
        $this->assertSame('manage', \App\Enums\Support\SupportActivityType::OTHER->permission());
        $this->assertTrue(\App\Enums\Support\SupportActivityType::COMPONENT_REPLACEMENT->requiresMaintenance());
    }

    public function test_schema_has_unique_uuid_restrict_history_and_no_cascade_deletion(): void
    {
        $s = $this->scenario();
        $copy = $s['activity']->fresh()->getRawOriginal();
        unset($copy['id']);
        try {
            DB::table('vending_support_activities')->insert($copy);
            $this->fail('UUID must be unique.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('vending_support_activities', 1);
        }
        try {
            DB::table('users')->where('id', $s['user']->id)->delete();
            $this->fail('Historical actor must not disappear.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertNotNull($s['user']->fresh());
        }
        try {
            $s['activity']->delete();
            $this->fail('Deletion must be guarded.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('vending_support_activities', 1);
        }
        try {
            (require base_path('database/migrations/2026_09_08_150000_create_vending_support_activities.php'))->down();
            $this->fail('Rollback must not destroy history.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('vending_support_activities', 1);
        }
    }

    public function test_mysql_ddl_compiles_but_does_not_claim_mysql_execution(): void
    {
        $connection = new \Illuminate\Database\MySqlConnection(static function () {
            throw new \LogicException('No external DB connection allowed.');
        }, 'isolated_ddl_test');
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        try {
            \Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());
            $statements = $connection->pretend(function (): void {
                (require base_path('database/migrations/2026_09_08_150000_create_vending_support_activities.php'))->up();
            });
            $sql = strtolower(implode("\n", array_column($statements, 'query')));
            $this->assertStringContainsString('bigint unsigned', $sql);
            $this->assertStringContainsString('unique', $sql);
            $this->assertStringContainsString('support_activity_employee_status', $sql);
            $this->assertStringContainsString('support_activity_machine_status', $sql);
            $this->assertStringContainsString('on delete restrict', $sql);
            $this->assertStringNotContainsString('on delete cascade', $sql);
            $this->assertStringContainsString('decimal(10, 7)', $sql);
        } finally {
            \Illuminate\Support\Facades\Schema::swap($schema);
        }
    }

    public function test_stale_conditional_transition_cannot_overwrite_first_winner(): void
    {
        $s = $this->scenario();
        $stale = $s['activity']->fresh();
        $this->postJson('/support/activities/'.$stale->uuid.'/start', $this->gps())->assertOk();
        $changed = VendingSupportActivity::query()->whereKey($stale->id)->where('status', $stale->status->value)
            ->update(['status' => 'CANCELLED']);
        $this->assertSame(0, $changed);
        $this->assertSame('IN_PROGRESS', $stale->fresh()->status->value);
        $this->assertDatabaseCount('vending_support_activity_events', 3);
        // This proves stale CAS rejection, NOT a simultaneous MySQL process race.
    }

    public function test_read_scope_and_pagination_do_not_expand_with_view_all(): void
    {
        $s = $this->scenario();
        $reader = $this->technician(['view', 'view_all']);
        $this->assignment($s['machine'], $reader->employee);
        $this->actingAs($reader);
        $this->getJson('/support/activities')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('per_page', 25);
        $this->getJson('/support/activities/'.$s['activity']->uuid)->assertNotFound();
        $this->actingAs($s['user'])->getJson('/support/activities?per_page=100000')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('per_page', 25);
    }

    public function test_human_mutations_require_csrf_outside_test_bypass(): void
    {
        $s = $this->scenario();
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps())->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame('ASSIGNED', $s['activity']->fresh()->status->value);
    }

    public function test_create_operation_replay_is_one_activity_and_changed_payload_conflicts(): void
    {
        $s = $this->scenario();
        $data = ['client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $s['machine']->id,
            'employee_id' => $s['user']->employee_id, 'activity_type' => 'MAINTENANCE', 'title' => 'Replay fixture'];
        $first = $this->postJson('/support/activities', $data)->assertCreated()->json('activity.uuid');
        $this->postJson('/support/activities', $data)->assertSuccessful()->assertJsonPath('activity.uuid', $first);
        $this->assertDatabaseCount('vending_support_activities', 2);
        $this->postJson('/support/activities', array_replace($data, ['title' => 'Tampered']))->assertConflict();
        $this->assertDatabaseCount('vending_support_activities', 2);
    }

    public function test_create_receipt_revalidates_authorization_and_normalizes_uuid_and_payload(): void
    {
        $s = $this->scenario();
        $data = ['client_operation_uuid' => strtoupper((string) Str::uuid()), 'vending_machine_id' => (string) $s['machine']->id,
            'employee_id' => (string) $s['user']->employee_id, 'activity_type' => 'MAINTENANCE', 'title' => 'Canonical',
            'scheduled_at' => '2026-09-09T10:00:00-06:00'];
        $uuid = $this->postJson('/support/activities', $data)->assertCreated()->json('activity.uuid');
        $this->postJson('/support/activities', array_reverse(array_replace($data, [
            'client_operation_uuid' => strtolower($data['client_operation_uuid']),
            'vending_machine_id' => $s['machine']->id, 'employee_id' => $s['user']->employee_id,
            'scheduled_at' => '2026-09-09T16:00:00Z',
        ]), true))->assertSuccessful()->assertJsonPath('activity.uuid', $uuid);
        $s['user']->roles()->detach();
        $this->postJson('/support/activities', $data)->assertForbidden();
        $this->assertDatabaseCount('vending_support_activities', 2);
    }

    public function test_missing_invalid_and_cross_intent_operation_keys_are_rejected(): void
    {
        $s = $this->scenario();
        $data = ['vending_machine_id' => $s['machine']->id, 'employee_id' => $s['user']->employee_id,
            'activity_type' => 'MAINTENANCE', 'title' => 'Key validation'];
        $this->postJson('/support/activities', $data)->assertUnprocessable();
        $this->postJson('/support/activities', $data + ['client_operation_uuid' => 'invalid'])->assertUnprocessable();
        $key = (string) Str::uuid();
        app(\App\Services\Support\SupportOperations::class)->run(
            \App\Services\Support\SupportActor::user($s['user']), $key, ['intent' => 'different-domain'],
            static fn () => ['fixture' => 'unrelated']);
        $this->postJson('/support/activities', $data + ['client_operation_uuid' => $key])->assertConflict();
        $this->assertDatabaseCount('vending_support_activities', 1);
    }

    public function test_unknown_presence_policy_and_false_snapshot_never_bypass_geofence(): void
    {
        $s = $this->scenario();
        foreach ([
            ['presence_policy' => 'REMOTE_UNAPPROVED', 'requires_physical_presence' => true],
            ['presence_policy' => 'FIELD_PHYSICAL_V1', 'requires_physical_presence' => false],
        ] as $changes) {
            DB::table('vending_support_activities')->where('id', $s['activity']->id)->update($changes);
            $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps())->assertConflict();
        }
        $this->assertNull($s['activity']->fresh()->started_at);
    }

    public function test_activity_mutations_use_existing_throttle(): void
    {
        $s = $this->scenario();
        $key = 'activity-test-'.Str::uuid();
        \Illuminate\Support\Facades\RateLimiter::for('support-write',
            static fn () => \Illuminate\Cache\RateLimiting\Limit::perMinute(1)->by($key));
        $this->postJson('/support/activities/'.$s['activity']->uuid.'/start', $this->gps())->assertOk();
        $this->postJson('/support/activities/'.$s['activity']->uuid.'/complete')->assertStatus(429);
        $this->assertSame('IN_PROGRESS', $s['activity']->fresh()->status->value);
    }

    public function test_physical_maintenance_starts_and_completes_without_attendance(): void
    {
        $s = $this->scenario();
        $service = app(SupportActivityService::class);
        $started = $service->start($s['activity']->uuid, $this->gps());
        $this->assertSame('IN_PROGRESS', $started->status->value);
        $this->assertSame('INSIDE', $started->geofence_result->value);
        $this->assertSame($s['geofence']->id, $started->machine_geofence_id);
        $this->assertFalse($s['assignment']->fresh()->attendance_allowed);
        $completed = $service->complete($started->uuid);
        $this->assertSame('COMPLETED', $completed->status->value);
        $this->assertNotNull($completed->completed_at);
        $this->assertDatabaseCount('vending_support_activities', 1);
    }
}
