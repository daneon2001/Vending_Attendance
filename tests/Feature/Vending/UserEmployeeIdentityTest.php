<?php

namespace Tests\Feature\Vending;

use App\Enums\Employees\EmployeeSource;
use App\Enums\Vending\AttendanceAuthorizationResult;
use App\Enums\Vending\AttendanceManifestEvidenceStatus;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Employees\FortiaEmployeeSyncService;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportTicketService;
use App\Services\Vending\AttendanceAuthorizationEvaluationService;
use App\Services\Vending\MachineAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class UserEmployeeIdentityTest extends VendingDeviceApiTestCase
{
    private const MIGRATION = 'database/migrations/2026_09_08_140000_add_employee_link_to_users_table.php';

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
        $this->travelBack();
        parent::tearDown();
    }

    private function account(): User
    {
        return User::factory()->create(['estatus' => true]);
    }

    private function fortiaEmployee(array $attributes = []): Employee
    {
        return $this->employee(array_merge([
            'employee_number' => 'FIELD-'.Str::uuid(),
            'source' => EmployeeSource::FORTIA,
            'source_external_id' => (string) Str::uuid(),
            'status' => 'A',
        ], $attributes));
    }

    /** Explicit trusted fixture assignment, not an application/public linking endpoint. */
    private function link(User $user, Employee $employee): void
    {
        $user->employee()->associate($employee);
        $user->save();
    }

    private function assertNoAttendance(): void
    {
        foreach (['vending_attendance_events', 'attendance_logs', 'attendance_dailies', 'attendances_raw'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_employees_can_exist_without_users_and_multiple_users_can_remain_unlinked(): void
    {
        $employee = $this->fortiaEmployee();
        $this->assertNull($employee->user);
        $this->assertDatabaseCount('users', 0);
        foreach (range(1, 2) as $unused) {
            $user = $this->account();
            $this->assertNull($user->employee);
        }
        $this->assertDatabaseCount('employees', 1);
        $this->assertNoAttendance();
    }

    public function test_authenticated_user_resolves_exact_persisted_employee_without_implicit_permissions(): void
    {
        $user = $this->account();
        $employee = $this->fortiaEmployee();
        $other = $this->fortiaEmployee();
        $this->link($user, $employee);
        $this->assertSame($employee->id, $user->fresh()->employee->id);
        $this->assertSame($user->id, $employee->fresh()->user->id);
        $this->actingAs($user);
        // Never trust a client-mutated model attribute or preloaded relationship.
        $user->employee_id = $other->id;
        $user->setRelation('employee', $other);
        $this->assertSame($employee->id, User::authenticatedEmployee()->id);
        $this->assertSame(0, $user->roles()->count());
        $this->assertFalse($user->hasPermission('support', 'report'));
        $this->assertFalse($user->hasPermission('attendance', 'manage'));
        $this->assertDatabaseCount('employee_machine_assignments', 0);
        $this->assertNoAttendance();
    }

    public function test_guest_missing_link_and_email_match_do_not_resolve_an_identity(): void
    {
        $user = $this->account();
        $this->fortiaEmployee(['email_company' => $user->email, 'full_name' => $user->name]);
        $this->assertNull(User::authenticatedEmployee());
        $this->actingAs($user);
        $this->assertNull(User::authenticatedEmployee());
        $this->assertNull($user->fresh()->employee_id);
        $this->assertNoAttendance();
    }

    public function test_unique_constraint_prevents_two_accounts_from_claiming_one_employee(): void
    {
        $employee = $this->fortiaEmployee();
        $a = $this->account();
        $b = $this->account();
        $this->link($a, $employee);
        try {
            $this->link($b, $employee);
            $this->fail('Duplicate employee link must be rejected by the DB.');
        } catch (QueryException) {
            $this->assertNull($b->fresh()->employee_id);
            $this->assertSame($a->id, $employee->fresh()->user->id);
        }
    }

    public function test_foreign_key_prevents_dangling_association(): void
    {
        $user = $this->account();
        try {
            DB::table('users')->where('id', $user->id)->update(['employee_id' => 999999999]);
            $this->fail('Missing employee must be rejected by the DB.');
        } catch (QueryException) {
            $this->assertNull($user->fresh()->employee_id);
        }
    }

    public function test_identity_is_not_mass_assignable_or_implicitly_exposed_in_auth_serialization(): void
    {
        $user = $this->account();
        $employee = $this->fortiaEmployee();
        $this->assertFalse($user->isFillable('employee_id'));
        $this->link($user, $employee);
        $serialized = $user->fresh()->load('employee')->toArray();
        $this->assertArrayNotHasKey('employee_id', $serialized);
        $this->assertArrayNotHasKey('employee', $serialized);
        $this->assertNoAttendance();
    }

    public function test_inactive_employee_and_account_fail_closed_and_status_is_reloaded(): void
    {
        $user = $this->account();
        $employee = $this->fortiaEmployee();
        $this->link($user, $employee);
        $this->actingAs($user->fresh()->load('employee'));
        $employee->update(['status' => 'B']);
        $this->assertNull(User::authenticatedEmployee());
        $employee->update(['status' => 'A']);
        $this->assertSame($employee->id, User::authenticatedEmployee()->id);
        DB::table('users')->where('id', $user->id)->update(['estatus' => false]);
        $this->assertNull(User::authenticatedEmployee());
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'A']);
        $this->assertSame($employee->id, $user->fresh()->employee_id);
        $this->assertNoAttendance();
    }

    public function test_manual_demo_legacy_and_incomplete_fortia_identity_do_not_resolve(): void
    {
        $user = $this->account();
        $employee = $this->fortiaEmployee();
        $this->link($user, $employee);
        $this->actingAs($user);
        foreach (['MANUAL', 'DEMO', 'LEGACY'] as $source) {
            $employee->update(['source' => $source]);
            $this->assertNull(User::authenticatedEmployee());
        }
        $employee->update(['source' => 'FORTIA', 'source_external_id' => null]);
        $this->assertNull(User::authenticatedEmployee());
        $employee->update(['source_external_id' => '']);
        $this->assertNull(User::authenticatedEmployee());
        $this->assertNoAttendance();
    }

    public function test_user_deletion_preserves_fortia_employee_and_stale_session_cannot_resolve(): void
    {
        $user = $this->account();
        $employee = $this->fortiaEmployee();
        $this->link($user, $employee);
        $this->actingAs($user->fresh()->load('employee'));
        $before = $employee->fresh()->getRawOriginal();
        $user->delete();
        $this->assertSame($before, $employee->fresh()->getRawOriginal());
        $this->assertNull($employee->fresh()->user);
        $this->assertNull(User::authenticatedEmployee());
        $this->assertNoAttendance();
    }

    public function test_deleting_linked_employee_is_restricted_without_cascade_to_account(): void
    {
        $user = $this->account();
        $employee = $this->fortiaEmployee();
        $this->link($user, $employee);
        $this->assertFalse(Schema::hasColumn('employees', 'deleted_at'));
        try {
            $employee->delete();
            $this->fail('Linked employee deletion must be restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('employees', ['id' => $employee->id]);
            $this->assertDatabaseHas('users', ['id' => $user->id, 'employee_id' => $employee->id]);
        }
    }

    public function test_link_and_resolution_do_not_change_assignment_or_enable_attendance(): void
    {
        $machine = $this->machine();
        $employee = $this->fortiaEmployee();
        $assignment = $this->assignment($machine, $employee, [
            'attendance_allowed' => false, 'maintenance_allowed' => true, 'enrollment_allowed' => false,
        ]);
        $before = $assignment->fresh()->getRawOriginal();
        $version = $machine->fresh()->employee_manifest_version;
        $user = $this->account();
        $this->link($user, $employee);
        $this->actingAs($user);
        $resolved = User::authenticatedEmployee();
        $authorization = app(MachineAuthorizationService::class);
        $this->assertTrue($authorization->canMaintain($resolved, $machine));
        $this->assertFalse($authorization->canAttend($resolved, $machine));
        $this->assertFalse($authorization->canEnroll($resolved, $machine));
        $decision = app(AttendanceAuthorizationEvaluationService::class)->evaluate(
            $resolved, $machine, $assignment->uuid, CarbonImmutable::now(),
            AttendanceManifestEvidenceStatus::CURRENT, AttendanceManifestEvidenceStatus::CURRENT,
        );
        $this->assertSame(AttendanceAuthorizationResult::DENIED, $decision['result']);
        $this->assertSame('ATTENDANCE_NOT_ALLOWED', $decision['reason']->value);
        $this->assertSame($before, $assignment->fresh()->getRawOriginal());
        $this->assertSame($version, $machine->fresh()->employee_manifest_version);
        $this->assertNoAttendance();
    }

    public function test_import_update_baja_and_reactivation_never_create_users_or_assignments(): void
    {
        config([
            'fortia.sync_driver' => 'http', 'fortia.base_url' => 'https://fortia.example.test',
            'employees.fortia.http_contract_approved' => true, 'employees.fortia.employees_path' => '/employees',
            'employees.fortia.api_token' => 'synthetic-test-token', 'employees.fortia.allow_write' => true,
        ]);
        $row = ['employee_number' => 'SUPPORT-0001', 'source_external_id' => 'corp-1',
            'full_name' => 'Synthetic Support Employee', 'status' => 'A'];
        Http::fake(['*' => Http::sequence()->push(['data' => [$row]])
            ->push(['data' => [[...$row, 'status' => 'B']]])
            ->push(['data' => [[...$row, 'full_name' => 'Synthetic Updated Support']]])]);
        $sync = app(FortiaEmployeeSyncService::class);
        $this->assertSame(1, $sync->sync(false)['created']);
        $employee = Employee::where('employee_number', 'SUPPORT-0001')->sole();
        $this->assertNull($employee->user);
        $this->assertSame(1, $sync->sync(false)['status_changed']);
        $this->assertSame('B', $employee->fresh()->status);
        $this->assertSame(1, $sync->sync(false)['status_changed']);
        $this->assertSame('A', $employee->fresh()->status);
        $this->assertSame('Synthetic Updated Support', $employee->fresh()->full_name);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employee_machine_assignments', 0);
        $this->assertNoAttendance();
    }

    public function test_link_does_not_bypass_support_http_authorization(): void
    {
        $user = $this->account();
        $this->link($user, $this->fortiaEmployee());
        $this->actingAs($user);
        $this->getJson('/support/tickets')->assertForbidden();
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertNoAttendance();
    }

    public function test_mysql_migration_compiles_unsigned_nullable_unique_restrict_fk_without_connection(): void
    {
        $connection = new MySqlConnection(static function () {
            throw new \LogicException('No MySQL connection is allowed in this test.');
        }, 'isolated_ddl_test');
        $schema = Schema::getFacadeRoot();
        try {
            Schema::swap($connection->getSchemaBuilder());
            $statements = $connection->pretend(function (): void {
                (require base_path(self::MIGRATION))->up();
            });
            $sql = strtolower(implode("\n", array_column($statements, 'query')));
            $this->assertStringContainsString('bigint unsigned null', $sql);
            $this->assertStringContainsString('unique', $sql);
            $this->assertStringContainsString('foreign key (`employee_id`) references `employees` (`id`) on delete restrict', $sql);
        } finally {
            Schema::swap($schema);
        }
    }

    public function test_migration_round_trip_preserves_existing_unlinked_accounts_and_employees(): void
    {
        $user = $this->account();
        $employee = $this->fortiaEmployee();
        $beforeUser = $user->fresh()->getRawOriginal();
        $beforeEmployee = $employee->fresh()->getRawOriginal();
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'employee_id'));
        $migration->up();
        $this->assertSame($beforeUser, $user->fresh()->getRawOriginal());
        $this->assertSame($beforeEmployee, $employee->fresh()->getRawOriginal());
        $this->assertNoAttendance();
    }

    public function test_migration_rollback_refuses_to_discard_existing_identity_links(): void
    {
        $user = $this->account();
        $employee = $this->fortiaEmployee();
        $this->link($user, $employee);
        try {
            (require base_path(self::MIGRATION))->down();
            $this->fail('Rollback must not silently discard identity.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot remove employee linkage while linked accounts exist.', $exception->getMessage());
        }
        $this->assertSame($employee->id, $user->fresh()->employee_id);
    }

    public function test_linked_employee_support_operations_do_not_create_attendance(): void
    {
        $user = $this->account();
        $role = Role::create(['name' => 'Synthetic field support']);
        $permission = Permission::firstOrCreate(['module' => 'support', 'action' => 'manage'], ['name' => 'Synthetic support']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $this->link($user, $this->fortiaEmployee());
        $this->actingAs($user);
        $this->assertNotNull(User::authenticatedEmployee());
        $actor = SupportActor::user($user);
        $service = app(SupportTicketService::class);
        $ticket = $service->create($actor, [
            'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $this->machine()->id,
            'category' => 'OTHER', 'title' => 'Synthetic support', 'description' => 'Synthetic technical operation.',
        ])['ticket'];
        $service->assign($actor, $ticket['uuid'], ['client_operation_uuid' => (string) Str::uuid(), 'assignee_id' => $user->id]);
        $service->comment($actor, $ticket['uuid'], ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'Synthetic comment']);
        $service->transition($actor, $ticket['uuid'], ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'IN_PROGRESS']);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertNoAttendance();
    }
}
