<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardExecutiveSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_dashboard_page_exposes_companies_and_locations_for_filters(): void
    {
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Unidad Centro',
            'code' => 'CTR',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('companies.0.name', 'Medical Life')
                ->where('locations.0.name', 'Unidad Centro')
            );
    }

    public function test_dashboard_summary_returns_executive_operational_payload(): void
    {
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        $location = Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Unidad Centro',
            'code' => 'CTR',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
        ]);

        $employeeWithAttendance = Employee::query()->create([
            'fortia_employee_id' => 1001,
            'company_id' => $company->id,
            'base_location_id' => $location->id,
            'name' => 'Ana',
            'last_name' => 'Lopez',
            'full_name' => 'Ana Lopez',
            'status' => 'A',
            'has_fingerprint' => true,
            'has_face_enrollment' => true,
            'face_enabled' => true,
            'face_status' => 'enrolled',
        ]);

        Employee::query()->create([
            'fortia_employee_id' => 1002,
            'company_id' => $company->id,
            'base_location_id' => $location->id,
            'name' => 'Luis',
            'last_name' => 'Perez',
            'full_name' => 'Luis Perez',
            'status' => 'A',
            'has_fingerprint' => false,
            'has_face_enrollment' => false,
            'face_enabled' => false,
            'face_status' => 'none',
        ]);

        $onlineClock = Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $location->id,
            'clock_name' => 'Reloj Centro 1',
            'serial_number' => 'CTR-1',
            'status' => 1,
            'monitoring_status' => 'online',
            'program_status' => 'online',
            'last_heartbeat_at' => now()->subMinutes(2),
        ]);

        Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $location->id,
            'clock_name' => 'Reloj Centro 2',
            'serial_number' => 'CTR-2',
            'status' => 1,
            'monitoring_status' => 'offline',
            'program_status' => 'offline',
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        AttendanceLog::query()->create([
            'log_id' => 1001,
            'employee_id' => $employeeWithAttendance->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'device_id' => $onlineClock->id,
            'log_date' => now()->subMinutes(15),
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'raw_payload' => ['provider' => 'fingerprint'],
        ]);

        $response = $this->getJson(route('dashboard.summary', [
            'range' => 'today',
            'company_id' => $company->id,
            'unit_id' => $location->id,
        ]));

        $response->assertOk()
            ->assertJsonStructure([
                'ok',
                'empty',
                'meta' => ['range', 'from', 'to', 'company_id', 'unit_id', 'generated_at_iso'],
                'summary' => [
                    'employees_active',
                    'attendance_registered',
                    'attendance_pending',
                    'attendance_coverage',
                    'entries_total',
                    'exits_total',
                    'last_updated_at',
                ],
                'clocks' => [
                    'total',
                    'online',
                    'offline',
                    'heartbeat_recent',
                    'heartbeat_stale',
                    'never_connected',
                    'status',
                    'status_label',
                ],
                'executive_status' => [
                    'level',
                    'title',
                    'message',
                    'bullets',
                ],
                'alerts',
                'locations',
                'locations_meta' => ['total', 'shown', 'has_more', 'mode', 'message', 'limit'],
                'recent_activity',
                'enrollment',
                'kpis',
                'charts' => [
                    'attendance_donut' => ['present', 'pending', 'percentage'],
                    'clocks_donut' => ['online', 'offline', 'stale'],
                    'hourly_activity',
                    'enrollment' => ['with_any_biometric', 'without_any_biometric', 'without_fingerprint', 'without_face', 'percentage'],
                ],
            ])
            ->assertJsonPath('summary.employees_active', 2)
            ->assertJsonPath('summary.attendance_registered', 1)
            ->assertJsonPath('summary.attendance_pending', 1)
            ->assertJsonPath('summary.attendance_coverage', 50)
            ->assertJsonPath('summary.entries_total', 1)
            ->assertJsonPath('summary.exits_total', 0)
            ->assertJsonPath('clocks.total', 2)
            ->assertJsonPath('clocks.online', 1)
            ->assertJsonPath('clocks.offline', 1)
            ->assertJsonPath('executive_status.level', 'critical')
            ->assertJsonPath('charts.attendance_donut.present', 1)
            ->assertJsonPath('charts.attendance_donut.pending', 1)
            ->assertJsonPath('charts.attendance_donut.percentage', 50)
            ->assertJsonPath('charts.clocks_donut.online', 1)
            ->assertJsonPath('charts.clocks_donut.offline', 1)
            ->assertJsonPath('charts.clocks_donut.stale', 0)
            ->assertJsonPath('clocks.heartbeat_recent', 1)
            ->assertJsonPath('clocks.heartbeat_stale', 1)
            ->assertJsonCount(24, 'charts.hourly_activity')
            ->assertJsonPath('charts.hourly_activity.0.hour', '00:00')
            ->assertJsonPath('locations_meta.total', 1)
            ->assertJsonPath('locations_meta.shown', 1)
            ->assertJsonPath('locations_meta.has_more', false)
            ->assertJsonPath('locations.0.name', 'Unidad Centro')
            ->assertJsonPath('recent_activity.0.employee_name', 'Ana Lopez')
            ->assertJsonPath('recent_activity.0.method', 'Huella')
            ->assertJsonPath('enrollment.employees_active', 2)
            ->assertJsonPath('enrollment.without_fingerprint', 1)
            ->assertJsonPath('enrollment.without_face', 1)
            ->assertJsonPath('enrollment.without_any_biometric', 1)
            ->assertJsonPath('enrollment.coverage_percentage', 50)
            ->assertJsonPath('charts.enrollment.with_any_biometric', 1)
            ->assertJsonPath('charts.enrollment.without_any_biometric', 1)
            ->assertJsonPath('charts.enrollment.percentage', 50);
    }

    public function test_dashboard_summary_limits_locations_to_priority_slice(): void
    {
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        for ($index = 1; $index <= 8; $index++) {
            $location = Location::query()->create([
                'company_id' => $company->id,
                'name' => 'Unidad '.$index,
                'code' => 'U'.$index,
                'timezone' => 'America/Mexico_City',
                'status' => 1,
            ]);

            $employee = Employee::query()->create([
                'fortia_employee_id' => 2000 + $index,
                'company_id' => $company->id,
                'base_location_id' => $location->id,
                'name' => 'Empleado '.$index,
                'last_name' => 'Demo',
                'full_name' => 'Empleado '.$index.' Demo',
                'status' => 'A',
                'has_fingerprint' => true,
                'has_face_enrollment' => true,
                'face_enabled' => true,
                'face_status' => 'enrolled',
            ]);

            $clock = Clock::query()->create([
                'company_id' => $company->id,
                'location_id' => $location->id,
                'clock_name' => 'Reloj '.$index,
                'serial_number' => 'R-'.$index,
                'status' => 1,
                'monitoring_status' => $index <= 2 ? 'offline' : 'online',
                'program_status' => $index <= 2 ? 'offline' : 'online',
                'last_heartbeat_at' => $index <= 2 ? now()->subMinutes(10) : now()->subMinutes(2),
            ]);

            if ($index > 2) {
                AttendanceLog::query()->create([
                    'log_id' => 3000 + $index,
                    'employee_id' => $employee->id,
                    'company_id' => $company->id,
                    'location_id' => $location->id,
                    'device_id' => $clock->id,
                    'log_date' => now()->subMinutes($index),
                    'log_type' => 1,
                    'source' => 'api',
                    'attendance_status' => 'valida',
                    'raw_payload' => ['provider' => 'fingerprint'],
                ]);
            }
        }

        $response = $this->getJson(route('dashboard.summary', [
            'range' => 'today',
            'company_id' => $company->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('locations_meta.total', 8)
            ->assertJsonPath('locations_meta.shown', 6)
            ->assertJsonPath('locations_meta.has_more', true)
            ->assertJsonPath('locations_meta.limit', 6)
            ->assertJsonCount(6, 'locations')
            ->assertJsonMissingPath('locations.6');
    }
}
