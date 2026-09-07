<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeStatusChange;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
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
        config()->set('operations.timezone', 'America/Mexico_City');
        config()->set('operations.storage_timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-06-12 10:15:00', 'America/Mexico_City'));

        try {
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
                'last_heartbeat_at' => Carbon::now('UTC')->subMinutes(2)->format('Y-m-d H:i:s'),
            ]);

            Clock::query()->create([
                'company_id' => $company->id,
                'location_id' => $location->id,
                'clock_name' => 'Reloj Centro 2',
                'serial_number' => 'CTR-2',
                'status' => 1,
                'monitoring_status' => 'offline',
                'program_status' => 'offline',
                'last_heartbeat_at' => Carbon::now('UTC')->subMinutes(10)->format('Y-m-d H:i:s'),
            ]);

            AttendanceLog::query()->create([
                'log_id' => 1001,
                'employee_id' => $employeeWithAttendance->id,
                'company_id' => $company->id,
                'location_id' => $location->id,
                'device_id' => $onlineClock->id,
                'log_date' => Carbon::now('UTC')->subMinutes(15)->format('Y-m-d H:i:s'),
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
                    'timezone' => ['name', 'label', 'offset'],
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
                    'executive_summary' => [
                        'attendance' => [
                            'active_employees',
                            'attended',
                            'pending',
                            'coverage_percent',
                            'entries',
                            'exits',
                        ],
                        'enrolment' => [
                            'active_employees',
                            'with_any_biometric',
                            'with_fingerprint',
                            'with_face',
                            'without_any_biometric',
                            'coverage_percent',
                        ],
                        'clocks' => [
                            'total',
                            'online',
                            'offline',
                            'stale',
                            'operational_status',
                            'is_business_hours',
                        ],
                        'compact_charts' => [
                            'attendance_donut',
                            'enrolment_bar',
                            'hourly_activity',
                            'clocks_status',
                        ],
                        'alerts',
                    ],
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
                ->assertJsonPath('timezone.name', 'America/Mexico_City')
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
                ->assertJsonPath('recent_activity.0.source_label', 'API')
                ->assertJsonPath('enrollment.employees_active', 2)
                ->assertJsonPath('enrollment.without_fingerprint', 1)
                ->assertJsonPath('enrollment.without_face', 1)
                ->assertJsonPath('enrollment.without_any_biometric', 1)
                ->assertJsonPath('enrollment.coverage_percentage', 50)
                ->assertJsonPath('charts.enrollment.with_any_biometric', 1)
                ->assertJsonPath('charts.enrollment.without_any_biometric', 1)
                ->assertJsonPath('charts.enrollment.percentage', 50)
                ->assertJsonPath('executive_summary.attendance.active_employees', 2)
                ->assertJsonPath('executive_summary.attendance.attended', 1)
                ->assertJsonPath('executive_summary.attendance.pending', 1)
                ->assertJsonPath('executive_summary.attendance.coverage_percent', 50)
                ->assertJsonPath('executive_summary.enrolment.with_fingerprint', 1)
                ->assertJsonPath('executive_summary.enrolment.with_face', 1)
                ->assertJsonPath('executive_summary.enrolment.without_any_biometric', 1)
                ->assertJsonPath('executive_summary.enrolment.coverage_percent', 50)
                ->assertJsonPath('executive_summary.clocks.total', 2)
                ->assertJsonPath('executive_summary.clocks.online', 1)
                ->assertJsonPath('executive_summary.clocks.offline', 1)
                ->assertJsonPath('executive_summary.clocks.stale', 1)
                ->assertJsonPath('executive_summary.clocks.is_business_hours', true)
                ->assertJsonCount(3, 'executive_summary.alerts');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_summary_excludes_terminated_employee_from_active_base_on_effective_date(): void
    {
        config()->set('operations.timezone', 'America/Mexico_City');
        config()->set('operations.storage_timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-06-19 10:15:00', 'America/Mexico_City'));

        try {
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

            Employee::query()->create([
                'fortia_employee_id' => 1101,
                'company_id' => $company->id,
                'base_location_id' => $location->id,
                'name' => 'Ana',
                'last_name' => 'Vigente',
                'full_name' => 'Ana Vigente',
                'status' => 'A',
                'has_fingerprint' => true,
                'has_face_enrollment' => true,
            ]);

            $terminatedEmployee = Employee::query()->create([
                'fortia_employee_id' => 1102,
                'company_id' => $company->id,
                'base_location_id' => $location->id,
                'name' => 'Luis',
                'last_name' => 'Baja',
                'full_name' => 'Luis Baja',
                'status' => 'B',
                'has_fingerprint' => false,
                'has_face_enrollment' => false,
            ]);

            $this->recordStatusChange($terminatedEmployee, 'A', 'B', '2026-06-19T08:00:00-06:00');

            $clock = Clock::query()->create([
                'company_id' => $company->id,
                'location_id' => $location->id,
                'clock_name' => 'Reloj Centro',
                'serial_number' => 'CTR-1',
                'status' => 1,
                'monitoring_status' => 'online',
                'program_status' => 'online',
                'last_heartbeat_at' => Carbon::now('UTC')->subMinutes(2)->format('Y-m-d H:i:s'),
            ]);

            AttendanceLog::query()->create([
                'log_id' => 7101,
                'employee_id' => $terminatedEmployee->id,
                'company_id' => $company->id,
                'location_id' => $location->id,
                'device_id' => $clock->id,
                'log_date' => '2026-06-19 15:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            $day18 = $this->getJson(route('dashboard.summary', [
                'range' => 'custom',
                'from_date' => '18/06/2026',
                'to_date' => '18/06/2026',
                'company_id' => $company->id,
                'unit_id' => $location->id,
            ]));

            $day18->assertOk()
                ->assertJsonPath('summary.employees_active', 2)
                ->assertJsonPath('summary.attendance_registered', 0)
                ->assertJsonPath('locations.0.employees_active', 2);

            $day19 = $this->getJson(route('dashboard.summary', [
                'range' => 'custom',
                'from_date' => '19/06/2026',
                'to_date' => '19/06/2026',
                'company_id' => $company->id,
                'unit_id' => $location->id,
            ]));

            $day19->assertOk()
                ->assertJsonPath('summary.employees_active', 1)
                ->assertJsonPath('summary.attendance_registered', 0)
                ->assertJsonPath('summary.attendance_pending', 1)
                ->assertJsonPath('summary.attendance_coverage', 0)
                ->assertJsonPath('locations.0.employees_active', 1)
                ->assertJsonPath('locations.0.attendance_registered', 0)
                ->assertJsonPath('charts.attendance_donut.present', 0)
                ->assertJsonPath('charts.attendance_donut.pending', 1)
                ->assertJsonPath('charts.employees_status.values.0', 1)
                ->assertJsonPath('charts.employees_status.values.1', 1)
                ->assertJsonPath('charts.people_present_by_day.values.0', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_summary_outside_business_hours_does_not_mark_clock_shutdown_as_critical(): void
    {
        config()->set('operations.timezone', 'America/Mexico_City');
        config()->set('operations.storage_timezone', 'UTC');

        Carbon::setTestNow(Carbon::parse('2026-06-12 22:15:00', 'America/Mexico_City'));

        try {
            $company = Company::query()->create([
                'name' => 'Medical Life',
                'code' => 'ML',
                'status' => 1,
            ]);

            $location = Location::query()->create([
                'company_id' => $company->id,
                'name' => 'Unidad Noche',
                'code' => 'NOC',
                'timezone' => 'America/Mexico_City',
                'status' => 1,
            ]);

            Clock::query()->create([
                'company_id' => $company->id,
                'location_id' => $location->id,
                'clock_name' => 'Reloj Noche',
                'serial_number' => 'NOC-1',
                'status' => 1,
                'monitoring_status' => 'offline',
                'program_status' => 'offline',
                'last_heartbeat_at' => Carbon::now('UTC')->subMinutes(30)->format('Y-m-d H:i:s'),
            ]);

            $response = $this->getJson(route('dashboard.summary', [
                'range' => 'today',
                'company_id' => $company->id,
                'unit_id' => $location->id,
            ]));

            $response->assertOk()
                ->assertJsonPath('clocks.is_business_hours', false)
                ->assertJsonPath('clocks.operational_status', 'info')
                ->assertJsonPath('clocks.status_label', 'Fuera de horario operativo')
                ->assertJsonPath('executive_summary.clocks.is_business_hours', false)
                ->assertJsonPath('executive_summary.clocks.operational_status', 'info')
                ->assertJsonPath('alerts.0.level', 'info')
                ->assertJsonPath('alerts.0.title', 'Conectividad fuera de horario')
                ->assertJsonMissingPath('alerts.1.level');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_summary_inside_business_hours_marks_offline_clocks_as_critical(): void
    {
        config()->set('operations.timezone', 'America/Mexico_City');
        config()->set('operations.storage_timezone', 'UTC');

        Carbon::setTestNow(Carbon::parse('2026-06-12 10:15:00', 'America/Mexico_City'));

        try {
            $company = Company::query()->create([
                'name' => 'Medical Life',
                'code' => 'ML',
                'status' => 1,
            ]);

            $location = Location::query()->create([
                'company_id' => $company->id,
                'name' => 'Unidad Dia',
                'code' => 'DIA',
                'timezone' => 'America/Mexico_City',
                'status' => 1,
            ]);

            Clock::query()->create([
                'company_id' => $company->id,
                'location_id' => $location->id,
                'clock_name' => 'Reloj Dia',
                'serial_number' => 'DIA-1',
                'status' => 1,
                'monitoring_status' => 'offline',
                'program_status' => 'offline',
                'last_heartbeat_at' => Carbon::now('UTC')->subMinutes(30)->format('Y-m-d H:i:s'),
            ]);

            $response = $this->getJson(route('dashboard.summary', [
                'range' => 'today',
                'company_id' => $company->id,
                'unit_id' => $location->id,
            ]));

            $response->assertOk()
                ->assertJsonPath('clocks.is_business_hours', true)
                ->assertJsonPath('clocks.operational_status', 'critical')
                ->assertJsonPath('executive_summary.clocks.is_business_hours', true)
                ->assertJsonPath('alerts.0.level', 'critical')
                ->assertJsonPath('alerts.0.title', 'Sin heartbeat reciente');
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function clockTabHours(): array
    {
        return [
            'before opening' => ['2026-09-07 06:59:59', false, 1],
            'opening inclusive' => ['2026-09-07 07:00:00', true, 2],
            'before closing' => ['2026-09-07 19:59:59', true, 2],
            'closing exclusive' => ['2026-09-07 20:00:00', false, 1],
            'before midnight' => ['2026-09-07 23:59:59', false, 1],
            'midnight' => ['2026-09-08 00:00:00', false, 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clockTabHours')]
    public function test_dashboard_summary_can_refresh_only_clocks_tab(string $localTime, bool $businessHours, int $alertCount): void
    {
        config()->set('operations.timezone', 'America/Mexico_City');
        config()->set('operations.storage_timezone', 'UTC');
        // The alert count deliberately differs inside/outside operational hours.
        // Laravel restores both Carbon clocks during teardown.
        $this->travelTo(Carbon::parse($localTime, 'America/Mexico_City'));

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

        Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $location->id,
            'clock_name' => 'Reloj Centro 1',
            'serial_number' => 'CTR-1',
            'status' => 1,
            'monitoring_status' => 'online',
            'program_status' => 'online',
            'last_heartbeat_at' => Carbon::now('UTC')->subMinutes(2)->format('Y-m-d H:i:s'),
        ]);

        Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $location->id,
            'clock_name' => 'Reloj Centro 2',
            'serial_number' => 'CTR-2',
            'status' => 1,
            'monitoring_status' => 'offline',
            'program_status' => 'offline',
            'last_heartbeat_at' => Carbon::now('UTC')->subMinutes(12)->format('Y-m-d H:i:s'),
        ]);

        $response = $this->getJson(route('dashboard.summary', [
            'range' => 'today',
            'company_id' => $company->id,
            'unit_id' => $location->id,
            'tab' => 'relojes',
        ]));

        $response->assertOk()
            ->assertJsonPath('active_tab', 'relojes')
            ->assertJsonPath('clocks.total', 2)
            ->assertJsonPath('clocks.online', 1)
            ->assertJsonPath('clocks.offline', 1)
            ->assertJsonPath('charts.clocks_donut.online', 1)
            ->assertJsonPath('charts.clocks_donut.offline', 1)
            ->assertJsonPath('clocks.is_business_hours', $businessHours)
            ->assertJsonCount($alertCount, 'connectivity_alerts')
            ->assertJsonMissingPath('alerts')
            ->assertJsonMissingPath('locations')
            ->assertJsonMissingPath('recent_activity')
            ->assertJsonMissingPath('enrollment')
            ->assertJsonMissingPath('charts.hourly_activity');
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

    public function test_dashboard_summary_limits_recent_activity_to_five_and_renames_unknown_event_type(): void
    {
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        $location = Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Unidad Norte',
            'code' => 'NTE',
            'timezone' => 'America/Mexico_City',
            'status' => 1,
        ]);

        $employee = Employee::query()->create([
            'fortia_employee_id' => 3001,
            'company_id' => $company->id,
            'base_location_id' => $location->id,
            'name' => 'Mario',
            'last_name' => 'Sanchez',
            'full_name' => 'Mario Sanchez',
            'status' => 'A',
            'has_fingerprint' => true,
        ]);

        $clock = Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $location->id,
            'clock_name' => 'Reloj Norte',
            'serial_number' => 'NTE-1',
            'status' => 1,
            'monitoring_status' => 'online',
            'program_status' => 'online',
            'last_heartbeat_at' => now()->subMinute(),
        ]);

        foreach (range(0, 5) as $index) {
            AttendanceLog::query()->create([
                'log_id' => 5000 + $index,
                'employee_id' => $employee->id,
                'company_id' => $company->id,
                'location_id' => $location->id,
                'device_id' => $clock->id,
                'log_date' => now()->subMinutes($index),
                'log_type' => $index === 0 ? 99 : 1,
                'source' => 'api',
                'attendance_status' => 'valida',
                'raw_payload' => ['provider' => $index === 0 ? 'face' : 'fingerprint'],
            ]);
        }

        $response = $this->getJson(route('dashboard.summary', [
            'range' => 'today',
            'company_id' => $company->id,
            'unit_id' => $location->id,
        ]));

        $response->assertOk()
            ->assertJsonCount(5, 'recent_activity')
            ->assertJsonPath('recent_activity.0.event_type', 'No clasificado')
            ->assertJsonPath('recent_activity.0.method', 'Rostro')
            ->assertJsonPath('recent_activity.0.source_label', 'API')
            ->assertJsonMissingPath('recent_activity.5');
    }

    public function test_dashboard_summary_converts_utc_timestamps_to_operational_timezone(): void
    {
        config()->set('operations.timezone', 'America/Mexico_City');
        config()->set('operations.storage_timezone', 'UTC');

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

        $employee = Employee::query()->create([
            'fortia_employee_id' => 4001,
            'company_id' => $company->id,
            'base_location_id' => $location->id,
            'name' => 'Sara',
            'last_name' => 'Mendez',
            'full_name' => 'Sara Mendez',
            'status' => 'A',
            'has_fingerprint' => true,
        ]);

        $clock = Clock::query()->create([
            'company_id' => $company->id,
            'location_id' => $location->id,
            'clock_name' => 'Reloj Centro',
            'serial_number' => 'CTR-UTC-1',
            'status' => 1,
            'monitoring_status' => 'online',
            'program_status' => 'online',
            'last_heartbeat_at' => '2026-05-19 15:29:00',
        ]);

        AttendanceLog::query()->create([
            'log_id' => 9001,
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'device_id' => $clock->id,
            'log_date' => '2026-05-19 15:25:00',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'raw_payload' => ['provider' => 'fingerprint'],
        ]);

        $response = $this->getJson(route('dashboard.summary', [
            'range' => 'custom',
            'from_date' => '19/05/2026',
            'to_date' => '19/05/2026',
            'company_id' => $company->id,
            'unit_id' => $location->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('timezone.name', 'America/Mexico_City')
            ->assertJsonPath('recent_activity.0.occurred_at', '2026-05-19T09:25:00-06:00')
            ->assertJsonPath('summary.latest_log_at', '2026-05-19T09:25:00-06:00');

        $bullets = implode(' ', $response->json('executive_status.bullets', []));
        $this->assertStringContainsString('19/05/2026 09:25', $bullets);
        $this->assertStringNotContainsString('19/05/2026 15:25', $bullets);

        $hourlyActivity = collect($response->json('charts.hourly_activity'));
        $nineAmBucket = $hourlyActivity->firstWhere('hour', '09:00');

        $this->assertNotNull($nineAmBucket);
        $this->assertSame(1, (int) ($nineAmBucket['entries'] ?? 0));
        $this->assertSame(1, (int) ($nineAmBucket['total'] ?? 0));
    }

    protected function recordStatusChange(Employee $employee, string $oldStatus, string $newStatus, string $effectiveAtLocal): EmployeeStatusChange
    {
        return EmployeeStatusChange::query()->create([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'fortia_employee_id' => $employee->fortia_employee_id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_at' => Carbon::parse($effectiveAtLocal)->utc(),
            'source' => 'test',
            'meta' => [
                'remote_updated_at' => Carbon::parse($effectiveAtLocal)->toIso8601String(),
            ],
        ]);
    }
}
