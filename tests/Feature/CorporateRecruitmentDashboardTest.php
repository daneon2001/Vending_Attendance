<?php

namespace Tests\Feature;

use App\Actions\SyncPermissionCatalog;
use App\Models\AttendanceLog;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeStatusChange;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\CorporateRecruitmentDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
        $excelTempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'asistencias-fortia-laravel-excel';
        config()->set('excel.temporary_files.local_path', $excelTempPath);
        File::ensureDirectoryExists($excelTempPath);
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
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
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
                ->assertJsonPath('global.first_check_at', '2026-06-16T18:38:45-06:00')
                ->assertJsonPath('global.last_check_at', '2026-06-16T18:38:45-06:00')
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
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
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
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
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
                ->assertJsonPath('global.attended', 2)
                ->assertJsonPath('global.total_checks', 2);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'custom',
                    'from_date' => '2026-06-16',
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
                ['Numero de empleado', 'Nombre completo del empleado', 'Unidad', 'Fecha', 'Total de checadas', 'Primera checada', 'Ultima checada', 'Checada 1'],
                $reportRows[0]
            );

            $reportBody = collect(array_slice($reportRows, 1))
                ->map(fn (array $row) => array_map(
                    fn ($value) => $value === null ? '' : (string) $value,
                    array_pad($row, 8, '')
                ))
                ->all();

            $this->assertContains(
                ['1001', 'Ana Lopez', 'Corporativo Central', '16/06/2026', '1', '07:00:00', '07:00:00', '07:00:00'],
                $reportBody
            );
            $this->assertContains(
                ['1002', 'Luis Perez', 'Corporativo Central', '16/06/2026', '1', '18:38:45', '18:38:45', '18:38:45'],
                $reportBody
            );
            $this->assertContains(
                ['1003', 'Maria Soto', 'Reclutamiento Norte', '16/06/2026', '0', '', '', ''],
                $reportBody
            );
            $this->assertFalse(collect($reportBody)->contains(fn (array $row) => $row[0] === '1004'));

            $summaryRows = $workbook->getSheetByName('Resumen')?->toArray('', true, true, false) ?? [];
            $summaryMap = collect(array_slice($summaryRows, 1))
                ->filter(fn (array $row) => ($row[0] ?? '') !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? '']);

            $reportCheckTotal = collect($reportBody)
                ->sum(fn (array $row) => (int) ($row[4] ?? 0));

            $this->assertSame('3', (string) $summaryMap->get('Total empleados'));
            $this->assertSame('2', (string) $summaryMap->get('Total con checada'));
            $this->assertSame('1', (string) $summaryMap->get('Total pendientes'));
            $this->assertSame('2', (string) $summaryMap->get('Total checadas'));
            $this->assertSame((int) $summary->json('global.total_checks'), $reportCheckTotal);
            $this->assertStringContainsString('Corporativo Central (87)', (string) $summaryMap->get('Unidades incluidas'));
            $this->assertStringContainsString('Reclutamiento Norte (171)', (string) $summaryMap->get('Unidades incluidas'));

            $rawRows = $workbook->getSheetByName('Detalle crudo')?->toArray('', true, true, false) ?? [];
            $this->assertSame(
                ['Attendance ID', 'Employee ID', 'Fortia employee ID', 'Numero de empleado', 'Nombre completo del empleado', 'Location ID', 'Unidad', 'Device ID', 'Reloj', 'Serie', 'Device serial', 'Log date UTC', 'Log date MX', 'Log type', 'Tipo', 'Fuente', 'Attendance status', 'Function int', 'Function str', 'Created at'],
                $rawRows[0]
            );
            $this->assertCount(3, $rawRows);

            $workbook->disconnectWorksheets();
            unset($workbook);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_export_xlsx_yesterday_route_with_explicit_dates_returns_file_and_uses_real_check_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view', 'export']]);
            $this->createLateSyncedAttendanceLog($fixture);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'yesterday',
                    'from_date' => '2026-06-16',
                    'to_date' => '2026-06-16',
                    'format' => 'xlsx',
                ]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            $workbook = $this->exportWorkbook($response);
            $summaryRows = $workbook->getSheetByName('Resumen')?->toArray('', true, true, false) ?? [];
            $summaryMap = collect(array_slice($summaryRows, 1))
                ->filter(fn (array $row) => ($row[0] ?? '') !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? '']);
            $rawRows = $workbook->getSheetByName('Detalle crudo')?->toArray('', true, true, false) ?? [];
            $rawBody = collect(array_slice($rawRows, 1))
                ->map(fn (array $row) => array_map(fn ($value) => $value === null ? '' : (string) $value, $row))
                ->all();

            $this->assertSame('1', (string) $summaryMap->get('Total con checada'));
            $this->assertSame('1', (string) $summaryMap->get('Total checadas'));
            $this->assertTrue(collect($rawBody)->contains(
                fn (array $row) => ($row[12] ?? '') === '2026-06-16 18:38:45'
                    && ($row[3] ?? '') === '1002'
            ));

            $workbook->disconnectWorksheets();
            unset($workbook);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_and_workbook_exclude_employee_after_effective_termination_date_but_keep_raw_check(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 10:00:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view', 'export']]);
            $terminatedEmployee = $fixture['corporate_employee_pending'];

            $terminatedEmployee->forceFill(['status' => 'B'])->save();
            $this->recordStatusChange($terminatedEmployee, 'A', 'B', '2026-06-19T08:00:00-06:00');

            AttendanceLog::query()->create([
                'log_id' => 9201,
                'employee_id' => $terminatedEmployee->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
                'log_date' => '2026-06-19 15:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            $day18 = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'custom',
                    'from_date' => '2026-06-18',
                    'to_date' => '2026-06-18',
                ]));

            $day18->assertOk()
                ->assertJsonPath('global.active_employees', 3)
                ->assertJsonPath('locations.0.summary.active_employees', 2)
                ->assertJsonPath('locations.1.summary.active_employees', 1);

            $day19 = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'custom',
                    'from_date' => '2026-06-19',
                    'to_date' => '2026-06-19',
                ]));

            $day19->assertOk()
                ->assertJsonPath('global.active_employees', 3)
                ->assertJsonPath('global.attended', 1)
                ->assertJsonPath('global.pending', 2)
                ->assertJsonPath('global.total_checks', 1)
                ->assertJsonPath('locations.0.summary.active_employees', 2)
                ->assertJsonPath('locations.0.summary.attended', 1)
                ->assertJsonPath('locations.0.summary.pending', 1)
                ->assertJsonPath('locations.0.summary.total_checks', 1)
                ->assertJsonPath('locations.1.summary.active_employees', 1)
                ->assertJsonPath('locations.1.summary.pending', 1);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'custom',
                    'from_date' => '2026-06-19',
                    'to_date' => '2026-06-19',
                    'format' => 'xlsx',
            ]));

            $response->assertOk();

            $workbook = $this->exportWorkbook($response);
            $reportRows = $workbook->getSheetByName('Reporte checadas')?->toArray('', true, true, false) ?? [];
            $reportBody = collect(array_slice($reportRows, 1))
                ->map(fn (array $row) => array_map(
                    fn ($value) => $value === null ? '' : (string) $value,
                    array_pad($row, 8, '')
                ))
                ->all();

            $this->assertContains(
                ['1002', 'Luis Perez', 'Corporativo Central', '19/06/2026', '1', '09:00:00', '09:00:00', '09:00:00'],
                $reportBody
            );

            $summaryRows = $workbook->getSheetByName('Resumen')?->toArray('', true, true, false) ?? [];
            $summaryMap = collect(array_slice($summaryRows, 1))
                ->filter(fn (array $row) => ($row[0] ?? '') !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? '']);

            $this->assertSame('3', (string) $summaryMap->get('Total empleados'));
            $this->assertSame('1', (string) $summaryMap->get('Total con checada'));
            $this->assertSame('2', (string) $summaryMap->get('Total pendientes'));
            $this->assertSame('1', (string) $summaryMap->get('Total checadas'));

            $rawRows = $workbook->getSheetByName('Detalle crudo')?->toArray('', true, true, false) ?? [];
            $rawBody = collect(array_slice($rawRows, 1))
                ->map(fn (array $row) => array_map(fn ($value) => $value === null ? '' : (string) $value, $row))
                ->all();

            $this->assertTrue(collect($rawBody)->contains(
                fn (array $row) => ($row[3] ?? '') === '1002'
                    && ($row[12] ?? '') === '2026-06-19 09:00:00'
            ));

            $workbook->disconnectWorksheets();
            unset($workbook);
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
                'device_serial' => $fixture['corporate_clock_offline']->serial_number,
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
            $this->assertFalse(collect($rawBody)->contains(fn (array $row) => ($row[8] ?? '') === 'Corpo 2'));
            $this->assertFalse(collect($rawBody)->contains(fn (array $row) => ($row[4] ?? '') === 'Maria Soto'));

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

            $rows = $this->exportCsvRows($response);
            $detailHeaderIndex = collect($rows)->search(fn (array $row) => $row === [
                'Fecha hora',
                'Unidad',
                'Reloj',
                'Serie',
                'Numero de empleado',
                'Tipo',
                'Fuente',
            ]);

            $this->assertNotFalse($detailHeaderIndex);
            $detailRows = array_values(array_filter(
                array_slice($rows, $detailHeaderIndex + 1),
                fn (array $row) => $row !== []
            ));

            $this->assertSame('2026-06-16T18:38:45-06:00', $detailRows[0][0] ?? null);
            $this->assertSame('1002', $detailRows[0][4] ?? null);
            $this->assertNotSame((string) $fixture['corporate_employee_pending']->id, $detailRows[0][4] ?? null);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_and_workbook_only_count_valid_attendance_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view', 'export']]);

            AttendanceLog::query()->create([
                'log_id' => 9301,
                'employee_id' => $fixture['corporate_employee_pending']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
                'log_date' => '2026-06-17 19:30:00',
                'log_type' => 1,
                'source' => 'manual',
                'attendance_status' => 'corregida',
            ]);

            $summary = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'today',
                ]));

            $summary->assertOk()
                ->assertJsonPath('global.active_employees', 3)
                ->assertJsonPath('global.attended', 2)
                ->assertJsonPath('global.pending', 1)
                ->assertJsonPath('global.total_checks', 3);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'today',
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
            $summaryRows = $workbook->getSheetByName('Resumen')?->toArray('', true, true, false) ?? [];
            $summaryMap = collect(array_slice($summaryRows, 1))
                ->filter(fn (array $row) => ($row[0] ?? '') !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? '']);

            $this->assertSame('3', (string) $summaryMap->get('Total empleados'));
            $this->assertSame('2', (string) $summaryMap->get('Total con checada'));
            $this->assertSame('1', (string) $summaryMap->get('Total pendientes'));
            $this->assertSame('3', (string) $summaryMap->get('Total checadas'));

            $rawRows = $workbook->getSheetByName('Detalle crudo')?->toArray('', true, true, false) ?? [];
            $rawBody = collect(array_slice($rawRows, 1))
                ->map(fn (array $row) => array_map(fn ($value) => $value === null ? '' : (string) $value, $row))
                ->all();

            $this->assertFalse(collect($rawBody)->contains(fn (array $row) => ($row[16] ?? '') === 'corregida'));

            $workbook->disconnectWorksheets();
            unset($workbook);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_yesterday_uses_exact_utc_window_for_dashboard_and_workbook(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-30 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view', 'export']]);

            AttendanceLog::query()->create([
                'log_id' => 9401,
                'employee_id' => $fixture['corporate_employee_attended']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
                'log_date' => '2026-06-29 05:59:59',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            AttendanceLog::query()->create([
                'log_id' => 9402,
                'employee_id' => $fixture['corporate_employee_attended']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
                'log_date' => '2026-06-29 06:00:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            AttendanceLog::query()->create([
                'log_id' => 9403,
                'employee_id' => $fixture['corporate_employee_attended']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
                'log_date' => '2026-06-30 05:59:59',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            AttendanceLog::query()->create([
                'log_id' => 9404,
                'employee_id' => $fixture['corporate_employee_attended']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
                'log_date' => '2026-06-30 06:00:00',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            $summary = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'yesterday',
                ]));

            $summary->assertOk()
                ->assertJsonPath('global.total_checks', 2)
                ->assertJsonPath('global.attended', 1)
                ->assertJsonPath('locations.0.summary.total_checks', 2);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'yesterday',
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
            $summaryRows = $workbook->getSheetByName('Resumen')?->toArray('', true, true, false) ?? [];
            $summaryMap = collect(array_slice($summaryRows, 1))
                ->filter(fn (array $row) => ($row[0] ?? '') !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? '']);

            $this->assertSame('2', (string) $summaryMap->get('Total checadas'));
            $this->assertSame('2026-06-29 06:00:00', (string) $summaryMap->get('Desde UTC'));
            $this->assertSame('2026-06-30 06:00:00', (string) $summaryMap->get('Hasta UTC (exclusivo)'));

            $rawRows = $workbook->getSheetByName('Detalle crudo')?->toArray('', true, true, false) ?? [];
            $rawBody = collect(array_slice($rawRows, 1))
                ->map(fn (array $row) => array_map(fn ($value) => $value === null ? '' : (string) $value, $row))
                ->all();

            $this->assertCount(2, $rawBody);
            $this->assertTrue(collect($rawBody)->contains(fn (array $row) => ($row[11] ?? '') === '2026-06-29 06:00:00'));
            $this->assertTrue(collect($rawBody)->contains(fn (array $row) => ($row[11] ?? '') === '2026-06-30 05:59:59'));
            $this->assertFalse(collect($rawBody)->contains(fn (array $row) => ($row[11] ?? '') === '2026-06-29 05:59:59'));
            $this->assertFalse(collect($rawBody)->contains(fn (array $row) => ($row[11] ?? '') === '2026-06-30 06:00:00'));

            $workbook->disconnectWorksheets();
            unset($workbook);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_and_workbook_count_distinct_attendance_employee_ids_without_report_universe_filter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view', 'export']]);

            AttendanceLog::query()->create([
                'log_id' => 9501,
                'employee_id' => $fixture['other_employee']->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
                'log_date' => '2026-06-17 16:30:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            $summary = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'today',
                ]));

            $summary->assertOk()
                ->assertJsonPath('global.active_employees', 4)
                ->assertJsonPath('global.attended', 3)
                ->assertJsonPath('global.pending', 1)
                ->assertJsonPath('global.total_checks', 4)
                ->assertJsonPath('locations.0.summary.active_employees', 3)
                ->assertJsonPath('locations.0.summary.attended', 2)
                ->assertJsonPath('locations.0.summary.pending', 1);

            $response = $this->actingAs($user)
                ->get(route('dashboard.corporativo-reclutamiento.export', [
                    'range' => 'today',
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
            $summaryRows = $workbook->getSheetByName('Resumen')?->toArray('', true, true, false) ?? [];
            $summaryMap = collect(array_slice($summaryRows, 1))
                ->filter(fn (array $row) => ($row[0] ?? '') !== '')
                ->mapWithKeys(fn (array $row) => [$row[0] => $row[1] ?? '']);

            $this->assertSame('4', (string) $summaryMap->get('Total empleados'));
            $this->assertSame('3', (string) $summaryMap->get('Total con checada'));
            $this->assertSame('1', (string) $summaryMap->get('Total pendientes'));
            $this->assertSame('4', (string) $summaryMap->get('Total checadas'));
            $this->assertSame('75%', (string) $summaryMap->get('Cobertura'));
            $this->assertContains(
                ['1004', 'Jorge Ruiz', 'Corporativo Central', '17/06/2026', '1', '10:30:00', '10:30:00', '10:30:00', ''],
                $reportBody
            );
            $this->assertSame(
                3,
                collect($reportBody)
                    ->filter(fn (array $row) => (int) ($row[4] ?? 0) > 0)
                    ->pluck(0)
                    ->unique()
                    ->count()
            );
            $rawRows = $workbook->getSheetByName('Detalle crudo')?->toArray('', true, true, false) ?? [];
            $rawBody = collect(array_slice($rawRows, 1))
                ->map(fn (array $row) => array_map(fn ($value) => $value === null ? '' : (string) $value, $row))
                ->all();

            $this->assertCount(4, $rawBody);
            $this->assertSame(
                3,
                collect($rawBody)
                    ->pluck(1)
                    ->filter()
                    ->unique()
                    ->count()
            );

            $workbook->disconnectWorksheets();
            unset($workbook);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_summary_builds_collaborator_universe_by_unit_without_global_duplicates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 10:30:00', 'America/Mexico_City'));

        try {
            $fixture = $this->seedDashboardFixture();
            $user = $this->makeUserWithPermissions(['dashboard' => ['view']]);
            $floatingEmployee = Employee::query()->create([
                'fortia_employee_id' => 1010,
                'company_id' => $fixture['company']->id,
                'base_location_id' => 999,
                'name' => 'Patricia',
                'last_name' => 'Vega',
                'full_name' => 'Patricia Vega',
                'status' => 'B',
            ]);

            AttendanceLog::query()->create([
                'log_id' => 9601,
                'employee_id' => $floatingEmployee->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['corporate']->id,
                'device_id' => $fixture['corporate_clock_online']->id,
                'device_serial' => $fixture['corporate_clock_online']->serial_number,
                'log_date' => '2026-06-17 15:30:00',
                'log_type' => 1,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            AttendanceLog::query()->create([
                'log_id' => 9602,
                'employee_id' => $floatingEmployee->id,
                'company_id' => $fixture['company']->id,
                'location_id' => $fixture['recruitment']->id,
                'device_id' => $fixture['recruitment_clock_stale']->id,
                'device_serial' => $fixture['recruitment_clock_stale']->serial_number,
                'log_date' => '2026-06-17 17:00:00',
                'log_type' => 2,
                'source' => 'api',
                'attendance_status' => 'valida',
            ]);

            $summary = $this->actingAs($user)
                ->getJson(route('dashboard.corporativo-reclutamiento.summary', [
                    'range' => 'today',
                ]));

            $summary->assertOk()
                ->assertJsonPath('locations.0.code', '87')
                ->assertJsonPath('locations.0.summary.active_employees', 3)
                ->assertJsonPath('locations.0.summary.attended', 2)
                ->assertJsonPath('locations.0.summary.pending', 1)
                ->assertJsonPath('locations.0.summary.coverage_percent', 66.7)
                ->assertJsonPath('locations.1.fortia_location_id', 171)
                ->assertJsonPath('locations.1.summary.active_employees', 2)
                ->assertJsonPath('locations.1.summary.attended', 2)
                ->assertJsonPath('locations.1.summary.pending', 0)
                ->assertJsonPath('locations.1.summary.coverage_percent', 100)
                ->assertJsonPath('global.active_employees', 4)
                ->assertJsonPath('global.attended', 3)
                ->assertJsonPath('global.pending', 1)
                ->assertJsonPath('global.coverage_percent', 75);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_service_prefers_operational_employee_number_before_internal_id(): void
    {
        $service = new class extends CorporateRecruitmentDashboardService
        {
            public function exposeVisibleEmployeeNumber(?Employee $employee, mixed $fallbackId): string
            {
                return $this->resolveVisibleEmployeeNumber($employee, $fallbackId);
            }
        };

        $fortiaEmployee = (new Employee())->forceFill([
            'id' => 504,
            'fortia_employee_id' => 12015,
            'employee_code' => 'EMP-504',
        ]);
        $employeeCodeEmployee = (new Employee())->forceFill([
            'id' => 505,
            'fortia_employee_id' => null,
            'employee_code' => 'EMP-505',
        ]);
        $fallbackEmployee = (new Employee())->forceFill([
            'id' => 506,
            'fortia_employee_id' => null,
        ]);

        $this->assertSame('12015', $service->exposeVisibleEmployeeNumber($fortiaEmployee, 504));
        $this->assertSame('EMP-505', $service->exposeVisibleEmployeeNumber($employeeCodeEmployee, 505));
        $this->assertSame('506', $service->exposeVisibleEmployeeNumber($fallbackEmployee, 506));
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
            'device_serial' => $corporateClockOnline->serial_number,
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
            'device_serial' => $corporateClockOnline->serial_number,
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
            'device_serial' => $recruitmentClockStale->serial_number,
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
            'device_serial' => $otherClock->serial_number,
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
            'device_serial' => $fixture['corporate_clock_online']->serial_number,
            'log_date' => '2026-06-17 00:38:45',
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'ingested_at_utc' => '2026-06-17 00:43:45',
            'raw_payload' => [
                'timezone' => 'America/Mexico_City',
                'punched_at_local' => '2026-06-16 18:38:45',
                'punched_at_utc' => '2026-06-17 00:38:45',
            ],
        ]);
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

    protected function exportWorkbook($response)
    {
        $binaryResponse = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binaryResponse);

        $path = $binaryResponse->getFile()->getPathname();
        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        return IOFactory::load($path);
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function exportCsvRows($response): array
    {
        $content = ltrim($response->streamedContent(), "\xEF\xBB\xBF");
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];

        return array_map(
            fn (string $line) => $line === '' ? [] : array_map(
                fn ($value) => (string) $value,
                str_getcsv($line)
            ),
            $lines
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function exportSheetRows($response, string $sheetName): array
    {
        $spreadsheet = $this->exportWorkbook($response);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        $this->assertNotNull($sheet, "No se encontro la hoja {$sheetName} en el export.");
        $rows = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }
}
