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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    public function test_dashboard_summary_uses_real_local_check_date_instead_of_sync_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view']]);
            $this->createLateSyncedAttendanceLog($fixture);

            $day16 = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'custom',
                    'from_date' => '2026-06-16',
                    'to_date' => '2026-06-16',
                ]));

            $day16->assertOk()
                ->assertJsonPath('global.attended', 1)
                ->assertJsonPath('global.total_checks', 1)
                ->assertJsonPath('global.first_check_at', '2026-06-16T18:00:00-06:00')
                ->assertJsonPath('global.last_check_at', '2026-06-16T18:00:00-06:00')
                ->assertJsonPath('locations.0.summary.attended', 1)
                ->assertJsonPath('locations.0.summary.total_checks', 1)
                ->assertJsonPath('locations.1.summary.total_checks', 0);

            $day17 = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'custom',
                    'from_date' => '2026-06-17',
                    'to_date' => '2026-06-17',
                ]));

            $day17->assertOk()
                ->assertJsonPath('global.attended', 2)
                ->assertJsonPath('global.total_checks', 3)
                ->assertJsonPath('locations.0.summary.attended', 1)
                ->assertJsonPath('locations.0.summary.total_checks', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_export_xlsx_uses_real_local_check_date_and_matches_dashboard_for_single_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view', 'export']]);

            AttendanceLog::query()->create([
                'log_id' => 5005,
                'employee_id' => $fixture['corporate_employee_attended']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'log_date' => '2026-06-16 13:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            AttendanceLog::query()->create([
                'log_id' => 5006,
                'employee_id' => $fixture['corporate_employee_attended']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'log_date' => '2026-06-17 20:30:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);
            $this->createLateSyncedAttendanceLog($fixture);

            $summary = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'custom',
                    'from_date' => '2026-06-16',
                    'to_date' => '2026-06-16',
                ]));

            $summary->assertOk()
                ->assertJsonPath('global.attended', 1)
                ->assertJsonPath('global.total_checks', 1);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'custom',
                    'from_date' => '2026-06-16',
                    'to_date' => '2026-06-17',
                    'to_date' => '2026-06-16',
                    'format' => 'xlsx',
                ]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            $workbook = $this->exportWorkbook($response);

            $this->assertSame(
                ['Reporte checadas', 'Resumen', 'Detalle crudo'],
                $workbook->getSheetNames()
            );

            $reportRows = $workbook->getSheetByName('Reporte checadas')?->toArray('', true, true, false) ?? [];
            $this->assertSame(
                ['Numero de empleado', 'Nombre completo del empleado', 'Unidad', 'Fecha', 'Total de checadas', 'Primera checada', 'Ultima checada', 'Checada 1', 'Checada 2', 'Checada 3'],
                $reportRows[0]
            );

            $reportBody = collect(array_slice($reportRows, 1))
                ->map(fn (array $row) => array_map(
                    fn ($value) => $value === null ? '' : (string) $value,
                    array_pad($row, 10, '')
                ))
                ->all();

            $this->assertContains(
                ['1001', 'Ana Lopez', 'Corporativo Central', '16/06/2026', '1', '07:00:00', '07:00:00', '07:00:00', '', ''],
                $reportBody
            );
            $this->assertContains(
                ['1001', 'Ana Lopez', 'Corporativo Central', '17/06/2026', '3', '08:00:00', '14:30:00', '08:00:00', '12:00:00', '14:30:00'],
                $reportBody
            );
            $this->assertContains(
                ['1002', 'Luis Perez', 'Corporativo Central', '17/06/2026', '0', '', '', '', '', ''],
                $reportBody
            );
            $this->assertContains(
                ['1003', 'Maria Soto', 'Reclutamiento Norte', '17/06/2026', '1', '07:30:00', '07:30:00', '07:30:00', '', ''],
                $reportBody
            );
            $this->assertFalse(collect($reportBody)->contains(fn (array $row) => $row[0] === '1004'));

            $summaryRows = $workbook->getSheetByName('Resumen')?->toArray('', true, true, false) ?? [];
            $summaryMap = collect(array_slice($summaryRows, 1))
                ->filter(fn (array $row) => ($row[0] ?? '') !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? '']);

            $this->assertSame('3', (string) $summaryMap->get('Total empleados'));
            $this->assertSame('2', (string) $summaryMap->get('Total con checada'));
            $this->assertSame('1', (string) $summaryMap->get('Total pendientes'));
            $this->assertSame('5', (string) $summaryMap->get('Total checadas'));
            $this->assertStringContainsString('Corporativo Central (87)', (string) $summaryMap->get('Unidades incluidas'));
            $this->assertStringContainsString('Reclutamiento Norte (171)', (string) $summaryMap->get('Unidades incluidas'));

            $rawRows = $workbook->getSheetByName('Detalle crudo')?->toArray('', true, true, false) ?? [];
            $this->assertSame(
                ['Numero de empleado', 'Nombre completo del empleado', 'Fecha hora local', 'Unidad', 'Reloj', 'Serie', 'Tipo', 'Fuente', 'Status'],
                $rawRows[0]
            );
            $this->assertCount(6, $rawRows);

            $workbook->disconnectWorksheets();
            unset($workbook);
            $globalRows = $this->exportSheetRows($response, 'Resumen global');
            $detailRows = $this->exportSheetRows($response, 'Detalle checadas');

            $globalMap = collect($globalRows)
                ->filter(fn (array $row) => isset($row[0], $row[1]) && is_string($row[0]) && $row[0] !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1]])
                ->all();

            $this->assertSame('1', (string) ($globalMap['Asistieron'] ?? null));
            $this->assertSame('1', (string) ($globalMap['Total checadas'] ?? null));
            $this->assertCount(2, $detailRows);
            $this->assertSame('2026-06-16T18:00:00-06:00', $detailRows[1][0] ?? null);
            $this->assertSame((string) $fixture['corporate_employee_pending']->id, (string) ($detailRows[1][4] ?? null));
        } finally {
            Carbon::setTestNow();
        }
    }


    public function test_export_csv_uses_real_local_check_date_in_detail_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view', 'export']]);

            AttendanceLog::query()->create([
                'log_id' => 5101,
                'employee_id' => $fixture['corporate_employee_attended']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_offline']->id,
                'log_date' => '2026-06-17 19:00:00',
                'log_type' => 2,
                'source' => 'manual',
                'attendance_status' => 'corregida',
            ]);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'today',
                    'unit_id' => $fixture['corporate']->id,
                    'clock_id' => $fixture['corporate_clock_online']->id,
                    'format' => 'xlsx',
                ]));

            $response->assertOk();

            $workbook = $this->exportWorkbook($response);
            $reportRows = $workbook->getSheetByName('Reporte checadas')?->toArray('', true, true, false) ?? [];
            $reportBody = collect(array_slice($reportRows, 1))
                ->map(fn (array $row) => array_map(
                    fn ($value) => $value === null ? '' : (string) $value,
                    array_pad($row, 9, '')
                ))
                ->all();

            $this->assertCount(2, $reportBody);
            $this->assertContains(
                ['1001', 'Ana Lopez', 'Corporativo Central', '17/06/2026', '2', '08:00:00', '12:00:00', '08:00:00', '12:00:00'],
                $reportBody
            );
            $this->assertContains(
                ['1002', 'Luis Perez', 'Corporativo Central', '17/06/2026', '0', '', '', '', ''],
                $reportBody
            );

            $summaryRows = $workbook->getSheetByName('Resumen')?->toArray('', true, true, false) ?? [];
            $summaryMap = collect(array_slice($summaryRows, 1))
                ->filter(fn (array $row) => ($row[0] ?? '') !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? '']);

            $this->assertSame('2', (string) $summaryMap->get('Total empleados'));
            $this->assertSame('1', (string) $summaryMap->get('Total con checada'));
            $this->assertSame('2', (string) $summaryMap->get('Total checadas'));
            $this->assertStringContainsString('Corpo 1 (COR-1)', (string) $summaryMap->get('Relojes incluidos'));
            $this->assertStringNotContainsString('Corpo 2 (COR-2)', (string) $summaryMap->get('Relojes incluidos'));

            $rawRows = $workbook->getSheetByName('Detalle crudo')?->toArray('', true, true, false) ?? [];
            $rawBody = collect(array_slice($rawRows, 1))
                ->map(fn (array $row) => array_map(fn ($value) => $value === null ? '' : (string) $value, $row))
                ->all();
            $this->assertCount(2, $rawBody);
            $this->assertFalse(collect($rawBody)->contains(fn (array $row) => ($row[4] ?? '') === 'Corpo 2'));
            $this->assertFalse(collect($rawBody)->contains(fn (array $row) => ($row[1] ?? '') === 'Maria Soto'));

            $workbook->disconnectWorksheets();
            unset($workbook);
            $this->createLateSyncedAttendanceLog($fixture);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'custom',
                    'from_date' => '2026-06-16',
                    'to_date' => '2026-06-16',
                    'format' => 'csv',
                ]));

            $response->assertOk();
            $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

            $content = $response->streamedContent();

            $this->assertStringContainsString('2026-06-16T18:00:00-06:00', $content);
            $this->assertStringContainsString((string) $fixture['corporate_employee_pending']->id, $content);
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

    protected function createLateSyncedAttendanceLog(array $fixture): AttendanceLog
    {
        return AttendanceLog::query()->create([
            'log_id' => 9002,
            'employee_id' => $fixture['corporate_employee_pending']->id,
            'company_id' => $fixture['company']->id,
            'location_id' => $fixture['corporate']->id,
            'device_id' => $fixture['corporate_clock_online']->id,
            'log_date' => '2026-06-17 14:00:00',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'ingested_at_utc' => '2026-06-17 14:05:00',
            'raw_payload' => [
                'timezone' => 'America/Mexico_City',
                'punched_at_local' => '2026-06-16 18:00:00',
                'punched_at_utc' => '2026-06-17 00:00:00',
            ],
        ]);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function exportSheetRows($response, string $sheetName): array
    {
        $binaryResponse = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binaryResponse);

        $path = $binaryResponse->getFile()->getPathname();
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        return IOFactory::load($path);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        $this->assertNotNull($sheet, "No se encontro la hoja {$sheetName} en el export.");
        $rows = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }
}
