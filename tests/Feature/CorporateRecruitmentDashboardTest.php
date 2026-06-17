<?php

namespace Tests\Feature;

use App\Actions\SyncPermissionCatalog;
use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CorporateRecruitmentDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('operations.timezone', 'America/Mexico_City');
        config()->set('operations.storage_timezone', 'UTC');
    }

    public function test_dashboard_page_requires_dashboard_view_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard.corporativo-reclutamiento'))
            ->assertForbidden();
    }

    public function test_dashboard_page_renders_and_summary_only_includes_units_87_and_171(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $company = $fixture['company'];
            $corporate = $fixture['corporate'];
            $recruitment = $fixture['recruitment'];
            $other = $fixture['other'];
            $user = $this->makeUserWithPermissions(['dashboard' => ['view']]);

            $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard/CorporateRecruitment')
                    ->where('locations.0.code', '87')
                    ->where('locations.1.name', 'Reclutamiento Norte')
                );

            $response = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'today',
                ]));

            $response->assertOk()
                ->assertJsonCount(2, 'locations')
                ->assertJsonPath('locations.0.code', '87')
                ->assertJsonPath('locations.0.summary.active_employees', 2)
                ->assertJsonPath('locations.0.summary.attended', 1)
                ->assertJsonPath('locations.0.summary.pending', 1)
                ->assertJsonPath('locations.0.summary.total_checks', 2)
                ->assertJsonPath('locations.0.summary.entries', 1)
                ->assertJsonPath('locations.0.summary.exits', 1)
                ->assertJsonPath('locations.0.summary.first_check_at', '2026-06-17T08:00:00-06:00')
                ->assertJsonPath('locations.0.summary.last_check_at', '2026-06-17T12:00:00-06:00')
                ->assertJsonPath('locations.1.name', 'Reclutamiento Norte')
                ->assertJsonPath('locations.1.summary.active_employees', 1)
                ->assertJsonPath('locations.1.summary.attended', 1)
                ->assertJsonPath('locations.1.summary.pending', 0)
                ->assertJsonPath('locations.1.summary.total_checks', 1)
                ->assertJsonPath('global.active_employees', 3)
                ->assertJsonPath('global.attended', 2)
                ->assertJsonPath('global.pending', 1)
                ->assertJsonPath('global.total_checks', 3)
                ->assertJsonPath('global.entries', 2)
                ->assertJsonPath('global.exits', 1)
                ->assertJsonPath('global.clocks_total', 3)
                ->assertJsonPath('global.clocks_online', 1)
                ->assertJsonPath('global.clocks_offline', 1)
                ->assertJsonPath('global.clocks_stale', 1)
                ->assertJsonPath('clock_ranking.0.location_name', 'Corporativo Central')
                ->assertJsonPath('clock_ranking.0.total_checks', 2)
                ->assertJsonMissingPath('locations.2')
                ->assertJsonMissingPath('clock_ranking.3');

            $locationNames = collect($response->json('locations'))->pluck('name')->all();
            $this->assertNotContains($other->name, $locationNames);
            $this->assertNotSame($company->id, 0);
            $this->assertSame($corporate->id, (int) $response->json('locations.0.id'));
            $this->assertSame($recruitment->id, (int) $response->json('locations.1.id'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_summary_respects_range_and_unit_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $corporate = $fixture['corporate'];
            $recruitment = $fixture['recruitment'];
            $user = $this->makeUserWithPermissions(['dashboard' => ['view']]);

            AttendanceLog::query()->create([
                'log_id' => 9001,
                'employee_id' => $fixture['corporate_employee_attended']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $corporate->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'log_date' => '2026-06-16 15:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            $yesterday = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'yesterday',
                ]));

            $yesterday->assertOk()
                ->assertJsonPath('global.total_checks', 1)
                ->assertJsonPath('locations.0.summary.total_checks', 1)
                ->assertJsonPath('locations.1.summary.total_checks', 0);

            $unitScoped = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'today',
                    'unit_id' => $recruitment->id,
                ]));

            $unitScoped->assertOk()
                ->assertJsonCount(1, 'locations')
                ->assertJsonPath('locations.0.id', $recruitment->id)
                ->assertJsonPath('global.active_employees', 1)
                ->assertJsonPath('global.total_checks', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_export_requires_dashboard_export_permission_and_returns_file(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $this->seedDashboardFixture();
            $viewOnlyUser = $this->makeUserWithPermissions(['dashboard' => ['view']]);

            $this->actingAs($viewOnlyUser)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'today',
                    'format' => 'xlsx',
                ]))
                ->assertForbidden();

            $user = $this->makeUserWithPermissions(['dashboard' => ['view', 'export']]);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'today',
                    'format' => 'xlsx',
                ]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->assertHeader('content-disposition');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function seedDashboardFixture(): array
    {
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        $corporate = Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Corporativo Central',
            'code' => '87',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
        ]);

        $recruitment = Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Reclutamiento Norte',
            'code' => null,
            'fortia_location_id' => 171,
            'timezone' => 'America/Mexico_City',
            'status' => 1,
        ]);

        $other = Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Sucursal Externa',
            'code' => '999',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
        ]);

        $corporateEmployeeAttended = Employee::query()->create([
            'fortia_employee_id' => 1001,
            'company_id' => $company->id,
            'base_location_id' => 87,
            'name' => 'Ana',
            'last_name' => 'Lopez',
            'full_name' => 'Ana Lopez',
            'status' => 'A',
        ]);

        $corporateEmployeePending = Employee::query()->create([
            'fortia_employee_id' => 1002,
            'company_id' => $company->id,
            'base_location_id' => $corporate->id,
            'name' => 'Luis',
            'last_name' => 'Perez',
            'full_name' => 'Luis Perez',
            'status' => 'A',
        ]);

        $recruitmentEmployeeAttended = Employee::query()->create([
            'fortia_employee_id' => 1003,
            'company_id' => $company->id,
            'base_location_id' => 171,
            'name' => 'Maria',
            'last_name' => 'Soto',
            'full_name' => 'Maria Soto',
            'status' => 'A',
        ]);

        $otherEmployee = Employee::query()->create([
            'fortia_employee_id' => 1004,
            'company_id' => $company->id,
            'base_location_id' => 999,
            'name' => 'Jorge',
            'last_name' => 'Ruiz',
            'full_name' => 'Jorge Ruiz',
            'status' => 'A',
        ]);

        $corporateClockOnline = Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $corporate->id,
            'clock_name' => 'Corpo 1',
            'serial_number' => 'COR-1',
            'status' => 1,
            'last_heartbeat_at' => '2026-06-17 10:28:00',
            'last_status_message' => 'online',
        ]);

        $corporateClockOffline = Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $corporate->id,
            'clock_name' => 'Corpo 2',
            'serial_number' => 'COR-2',
            'status' => 1,
            'last_heartbeat_at' => null,
            'last_status_message' => 'offline',
        ]);

        $recruitmentClockStale = Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $recruitment->id,
            'clock_name' => 'Recruit 1',
            'serial_number' => 'REC-1',
            'status' => 1,
            'last_heartbeat_at' => '2026-06-17 09:50:00',
            'last_status_message' => 'stale',
        ]);

        $otherClock = Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $other->id,
            'clock_name' => 'Otro',
            'serial_number' => 'OTR-1',
            'status' => 1,
            'last_heartbeat_at' => '2026-06-17 10:29:00',
            'last_status_message' => 'online',
        ]);

        AttendanceLog::query()->create([
            'log_id' => 5001,
            'employee_id' => $corporateEmployeeAttended->id,
            'company_id' => $company->id,
            'location_id' => $corporate->id,
            'device_id' => $corporateClockOnline->id,
            'log_date' => '2026-06-17 14:00:00',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
        ]);

        AttendanceLog::query()->create([
            'log_id' => 5002,
            'employee_id' => $corporateEmployeeAttended->id,
            'company_id' => $company->id,
            'location_id' => $corporate->id,
            'device_id' => $corporateClockOnline->id,
            'log_date' => '2026-06-17 18:00:00',
            'log_type' => 2,
            'source' => 'api',
            'attendance_status' => 'valida',
        ]);

        AttendanceLog::query()->create([
            'log_id' => 5003,
            'employee_id' => $recruitmentEmployeeAttended->id,
            'company_id' => $company->id,
            'location_id' => $recruitment->id,
            'device_id' => $recruitmentClockStale->id,
            'log_date' => '2026-06-17 13:30:00',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
        ]);

        AttendanceLog::query()->create([
            'log_id' => 5004,
            'employee_id' => $otherEmployee->id,
            'company_id' => $company->id,
            'location_id' => $other->id,
            'device_id' => $otherClock->id,
            'log_date' => '2026-06-17 14:15:00',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
        ]);

        return [
            'company' => $company,
            'corporate' => $corporate,
            'recruitment' => $recruitment,
            'other' => $other,
            'corporate_employee_attended' => $corporateEmployeeAttended,
            'corporate_employee_pending' => $corporateEmployeePending,
            'recruitment_employee_attended' => $recruitmentEmployeeAttended,
            'other_employee' => $otherEmployee,
            'corporate_clock_online' => $corporateClockOnline,
            'corporate_clock_offline' => $corporateClockOffline,
            'recruitment_clock_stale' => $recruitmentClockStale,
            'other_clock' => $otherClock,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $permissions
     */
    protected function makeUserWithPermissions(array $permissions): User
    {
        $map = SyncPermissionCatalog::run();
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Role '.uniqid(),
            'description' => 'Role de prueba',
            'is_system' => false,
        ]);

        $permissionIds = collect($permissions)
            ->flatMap(function (array $actions, string $module) use ($map) {
                return collect($actions)
                    ->map(fn (string $action) => $map[$module][$action] ?? null)
                    ->filter();
            })
            ->values()
            ->all();

        $role->permissions()->sync($permissionIds);
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
