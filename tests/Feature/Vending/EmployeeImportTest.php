<?php

namespace Tests\Feature\Vending;

use App\Actions\SyncPermissionCatalog;
use App\Enums\Employees\EmployeeSource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeImportRun;
use App\Models\EmployeeMachineAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\Employees\EmployeeImportService;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;
use ZipArchive;

class EmployeeImportTest extends VendingDeviceApiTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = $this->userWith(['view', 'import', 'sync']);
        $this->actingAs($this->admin);
    }

    public function test_csv_preview_confirm_reimport_and_partial_file_preserve_identity_and_assignments(): void
    {
        $existing = $this->employee(['employee_number' => 'OTHER', 'source' => EmployeeSource::MANUAL]);
        $machine = $this->machine();
        $this->assignment($machine, $existing);
        $version = $machine->fresh()->employee_manifest_version;
        $csv = "\xEF\xBB\xBFNúmero de empleado,Nombre completo,Estado\n00042,Persona Sintética,ACTIVO\n00123,Otra Persona,BAJA\n";
        $preview = $this->upload($csv)->assertCreated()->json();
        $this->assertSame(2, $preview['summary']['valid_new']);
        $this->assertSame('00042', $preview['rows']['data'][0]['employee_number']);
        $this->assertDatabaseCount('employees', 1);
        $url = '/vending/employees/imports/'.$preview['uuid'].'/apply';
        $this->postJson($url, ['preview_hash' => $preview['preview_hash']])->assertUnprocessable();
        $payload = ['confirmed' => true, 'preview_hash' => $preview['preview_hash']];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('preview.status', 'COMPLETED');
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('employees', 3);
        $this->assertDatabaseHas('employees', ['employee_number' => '00042', 'fortia_employee_id' => null, 'source' => 'MANUAL']);
        $this->assertDatabaseCount('employee_machine_assignments', 1);
        $this->assertSame($version, $machine->fresh()->employee_manifest_version);
        $this->assertSame('A', $existing->fresh()->status);
        $this->upload($csv)->assertCreated()->assertJsonPath('summary.unchanged', 2);
        $audit = AuditLog::where('event', 'employee.import.completed')->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('Persona Sintética', $audit->toJson());
    }

    public function test_invalid_duplicate_and_fortia_owned_rows_are_excluded_without_aborting_valid_rows(): void
    {
        $fortia = $this->employee(['employee_number' => 'F001', 'source' => EmployeeSource::FORTIA, 'full_name' => 'Authoritative']);
        $preview = $this->upload("employee_number,full_name,status\nD1,One,A\nD1,Two,A\nF001,Overwrite,B\nBAD,,bogus\nGOOD,Valid,A\nFORM,=1+1,A\n")->assertCreated()->json();
        $this->assertSame(2, $preview['summary']['duplicates']);
        $this->assertSame(2, $preview['summary']['invalid']);
        $this->assertSame(1, $preview['summary']['conflicts']);
        $this->postJson('/vending/employees/imports/'.$preview['uuid'].'/apply', ['confirmed' => true, 'preview_hash' => $preview['preview_hash']])->assertOk();
        $this->assertSame('Authoritative', $fortia->fresh()->full_name);
        $this->assertSame(EmployeeSource::FORTIA, $fortia->fresh()->source);
        $this->assertDatabaseCount('employees', 2);
        $this->assertDatabaseHas('employees', ['employee_number' => 'GOOD']);
        $this->assertSame(0, EmployeeMachineAssignment::count());
    }

    public function test_manual_update_invalidates_only_affected_manifest_and_stale_preview_requires_reconfirmation(): void
    {
        $employee = $this->employee(['employee_number' => 'UPD', 'source' => EmployeeSource::MANUAL, 'full_name' => 'Before']);
        $machine = $this->machine();
        $other = $this->machine();
        $this->assignment($machine, $employee);
        $preview = $this->upload("CLA_TRAB,NOMBRE,ESTATUS_TRABAJADOR\nUPD,After,B\n")->assertCreated()->json();
        $employee->update(['full_name' => 'Changed while reviewing']);
        $version = $machine->fresh()->employee_manifest_version;
        $url = '/vending/employees/imports/'.$preview['uuid'].'/apply';
        $changed = $this->postJson($url, ['confirmed' => true, 'preview_hash' => $preview['preview_hash']])
            ->assertConflict()->assertJsonPath('stale', true)->json('preview');
        $this->assertSame('Changed while reviewing', $employee->fresh()->full_name);
        $this->postJson($url, ['confirmed' => true, 'preview_hash' => $changed['preview_hash']])->assertOk();
        $this->assertSame('B', $employee->fresh()->status);
        $this->assertSame($version + 1, $machine->fresh()->employee_manifest_version);
        $this->assertSame(1, $other->fresh()->employee_manifest_version);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.status_changed']);
    }

    public function test_permissions_actor_binding_expiry_and_pruning(): void
    {
        $preview = $this->upload("employee_number,full_name,status\nA,Test,A\n")->assertCreated()->json();
        $otherImporter = $this->userWith(['view', 'import']);
        $viewer = $this->userWith(['view']);
        $this->actingAs($otherImporter)->getJson('/vending/employees/imports/'.$preview['uuid'])->assertNotFound();
        $this->actingAs($otherImporter)->postJson('/vending/employees/imports/'.$preview['uuid'].'/apply', ['confirmed' => true, 'preview_hash' => $preview['preview_hash']])->assertNotFound();
        $this->actingAs($viewer)->get('/vending/employees')->assertOk();
        $this->upload("employee_number,full_name,status\nB,Test,A\n")->assertForbidden();
        $this->postJson('/vending/employees/fortia-sync', ['dry_run' => true])->assertForbidden();
        $this->actingAs($this->admin);
        $run = EmployeeImportRun::where('uuid', $preview['uuid'])->firstOrFail();
        $run->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/vending/employees/imports/'.$run->uuid)->assertGone();
        $this->artisan('employees:prune-imports')->assertSuccessful();
        $this->assertDatabaseCount('employee_import_rows', 0);
        $this->assertDatabaseCount('employee_import_runs', 1);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_preview_is_paginated_and_large_reasonable_file_is_bounded(): void
    {
        config(['employees.import.preview_per_page' => 20, 'employees.import.max_rows' => 300]);
        $csv = "employee_number,full_name,status\n";
        for ($i = 0; $i < 250; $i++) {
            $csv .= sprintf('%05d,Synthetic %d,A', $i, $i)."\n";
        }
        $preview = $this->upload($csv)->assertCreated()->json();
        $this->assertSame(250, $preview['summary']['total_rows']);
        $this->assertCount(20, $preview['rows']['data']);
        $this->getJson('/vending/employees/imports/'.$preview['uuid'].'?page=2')->assertOk()->assertJsonPath('rows.current_page', 2);
        config(['employees.import.max_rows' => 249]);
        $this->upload($csv)->assertUnprocessable();
    }

    public function test_mime_size_headers_and_malformed_archive_are_rejected(): void
    {
        foreach ([
            UploadedFile::fake()->createWithContent('employees.xls', 'arbitrary'),
            UploadedFile::fake()->createWithContent('employees.xlsx', 'not a zip'),
            UploadedFile::fake()->createWithContent('employees.csv', "employee_number,full_name\n1,Name\n"),
            UploadedFile::fake()->createWithContent('employees.csv', "employee_number,CLA_TRAB,full_name,status\n1,2,Name,A\n"),
        ] as $file) {
            $this->postJson('/vending/employees/imports', ['file' => $file])->assertUnprocessable();
        }
        config(['employees.import.max_file_kb' => 1]);
        $this->upload("employee_number,full_name,status\n".str_repeat('x', 2048))->assertUnprocessable();
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_xlsx_preserves_text_and_zero_format_and_never_executes_formulas(): void
    {
        $file = $this->xlsx('<row r="2"><c r="A2" t="inlineStr"><is><t>00001</t></is></c><c r="B2" t="inlineStr"><is><t>Persona</t></is></c><c r="C2" t="inlineStr"><is><t>A</t></is></c></row><row r="3"><c r="A3" s="1"><v>42</v></c><c r="B3" t="inlineStr"><is><t>Otra</t></is></c><c r="C3" t="inlineStr"><is><t>B</t></is></c></row><row r="8"><c r="A8"><f>HYPERLINK(&quot;https://example.invalid&quot;)</f><v>44</v></c><c r="B8" t="inlineStr"><is><t>Formula</t></is></c><c r="C8" t="inlineStr"><is><t>A</t></is></c></row>');
        $preview = $this->postJson('/vending/employees/imports', ['file' => $file])->assertCreated()->json();
        $this->assertSame('00001', $preview['rows']['data'][0]['employee_number']);
        $this->assertSame('00042', $preview['rows']['data'][1]['employee_number']);
        $this->assertSame(8, $preview['rows']['data'][2]['row_number']);
        $this->assertSame('INVALID', $preview['rows']['data'][2]['classification']);
        $this->assertSame(2, $preview['summary']['valid_new']);
    }

    public function test_xlsx_macros_external_entities_and_archive_expansion_limits_are_rejected(): void
    {
        foreach ([['xl/vbaProject.bin' => 'macro'], ['xl/externalLinks/link.xml' => '<link/>'], ['extra.xml' => '<!DOCTYPE x [<!ENTITY bad SYSTEM "file:///C:/secret">]><x/>']] as $extra) {
            $file = $this->xlsx('', $extra);
            $this->postJson('/vending/employees/imports', ['file' => $file])->assertUnprocessable();
        }
        config(['employees.import.xlsx_max_uncompressed_bytes' => 1024]);
        $this->postJson('/vending/employees/imports', ['file' => $this->xlsx(str_repeat(' ', 2048))])->assertUnprocessable();
        $this->assertDatabaseCount('employees', 0);
    }

    private function userWith(array $actions): User
    {
        $role = Role::create(['name' => 'Test '.uniqid()]);
        $role->permissions()->sync(SyncPermissionCatalog::resolveIds(['employees' => $actions]));
        $user = User::factory()->create(['estatus' => true, 'email_verified_at' => now()]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_actual_xlsx_workbook_shared_strings_unicode_and_apply(): void
    {
        $workbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->fromArray([['No. empleado', 'Empleado', 'Activo'], ['00077', 'Persona Sintética', 'true']]);
        $sheet->setCellValueExplicit('A2', '00077', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'vending-xlsx-');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save($path);
            $file = UploadedFile::fake()->createWithContent('synthetic.xlsx', file_get_contents($path));
            $preview = $this->postJson('/vending/employees/imports', ['file' => $file])->assertCreated()->json();
            $this->assertSame('00077', $preview['rows']['data'][0]['employee_number']);
            $this->postJson('/vending/employees/imports/'.$preview['uuid'].'/apply', ['confirmed' => true, 'preview_hash' => $preview['preview_hash']])->assertOk();
            $this->assertDatabaseHas('employees', ['employee_number' => '00077', 'full_name' => 'Persona Sintética', 'source' => 'MANUAL']);
        } finally {
            $workbook->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_legacy_import_is_opt_in_and_protected_sources_cannot_be_overwritten(): void
    {
        $service = app(\App\Services\Employees\EmployeeExcelImportService::class);
        $file = $this->xlsx('<row r="2"><c r="A2" t="inlineStr"><is><t>12345</t></is></c><c r="B2" t="inlineStr"><is><t>Overwrite</t></is></c><c r="C2" t="inlineStr"><is><t>A</t></is></c></row>');
        config(['employees.import.legacy_enabled' => false]);
        foreach (['preview', 'import', 'createMissingCatalogs'] as $method) {
            try {
                $service->{$method}($file);
                $this->fail('Legacy import must be explicitly enabled.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('preview', $exception->getMessage());
            }
        }
        config(['employees.import.legacy_enabled' => true]);
        $employee = $this->employee(['employee_number' => '12345', 'fortia_employee_id' => 12345, 'source' => EmployeeSource::FORTIA, 'full_name' => 'Keep']);
        $preview = $service->preview($file);
        $this->assertFalse($preview['can_import']);
        $this->assertStringContainsString('CONFLICT_SOURCE', json_encode($preview));
        $service->import($file);
        $this->assertSame('Keep', $employee->fresh()->full_name);
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_case_variant_duplicates_and_number_binding_preserve_zeroes(): void
    {
        $this->upload("employee_number,full_name,status\nabc,One,A\nABC,Two,A\n")->assertCreated()->assertJsonPath('summary.duplicates', 2);
        $employee = $this->employee(['employee_number' => '00042', 'source' => EmployeeSource::MANUAL]);
        $other = Employee::unguarded(fn () => $this->employee(['id' => 42, 'employee_number' => 'Different']));
        $this->assertSame($employee->id, (new Employee)->resolveRouteBinding('00042')->id);
        $this->assertSame($other->id, (new Employee)->resolveRouteBinding('42')->id);
    }

    private function upload(string $csv)
    {
        return $this->postJson('/vending/employees/imports', ['file' => UploadedFile::fake()->createWithContent('employees.csv', $csv)]);
    }

    public function test_apply_failure_rolls_back_all_employees_and_marks_run_failed(): void
    {
        $preview = $this->upload("employee_number,full_name,status\n00001,One,A\n00002,Two,A\n")->assertCreated()->json();
        $run = EmployeeImportRun::where('uuid', $preview['uuid'])->firstOrFail();
        \Illuminate\Support\Facades\Event::listen('eloquent.created: '.Employee::class, function (Employee $employee): void {
            if ($employee->employee_number === '00002') {
                throw new \RuntimeException('Synthetic failure: must not leak payload.');
            }
        });
        try {
            app(EmployeeImportService::class)->apply($run, $preview['preview_hash']);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('EMPLOYEE_IMPORT_APPLY_FAILED', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertDatabaseCount('employees', 0);
        $this->assertSame('FAILED', $run->fresh()->status->value);
        $this->assertSame(0, AuditLog::where('event', 'employee.created')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.import.failed']);
    }

    public function test_legacy_mock_sync_cannot_bypass_authentication_or_sync_permission(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\DevOnlyApi::class);
        auth()->logout();
        $this->postJson('/api/fortia-mock/sync-employees')->assertUnauthorized();
        $this->actingAs($this->userWith(['view']))->postJson('/api/fortia-mock/sync-employees')->assertForbidden();
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_mysql_json_key_order_does_not_invalidate_an_unchanged_import_snapshot(): void
    {
        $existing = $this->employee(['employee_number' => 'EDIT', 'source' => EmployeeSource::MANUAL, 'full_name' => 'Antes']);
        $preview = $this->upload("employee_number,full_name,status\nEDIT,José,B\nNEW,Niño,A\n")->assertCreated()->json();
        $preview = $this->mysqlOrderedPreview($preview);
        $this->assertDatabaseCount('employees', 1);
        $this->postJson('/vending/employees/imports/'.$preview['uuid'].'/apply', [
            'confirmed' => true, 'preview_hash' => $preview['preview_hash'],
        ])->assertOk()->assertJsonPath('stale', false)->assertJsonPath('preview.status', 'COMPLETED');
        $this->assertSame('José', $existing->fresh()->full_name);
        $this->assertSame('B', $existing->fresh()->status);
        $this->assertDatabaseHas('employees', ['employee_number' => 'NEW', 'full_name' => 'Niño', 'source' => 'MANUAL']);
    }

    public function test_mysql_ordered_snapshot_still_blocks_real_changes_and_requires_new_confirmation(): void
    {
        $employee = $this->employee(['employee_number' => 'EDIT', 'source' => EmployeeSource::MANUAL, 'full_name' => 'Antes']);
        $preview = $this->mysqlOrderedPreview($this->upload("employee_number,full_name,status\nEDIT,Después,B\nNEW,Nuevo,A\n")->assertCreated()->json());
        $employee->update(['full_name' => 'Cambio concurrente']);
        $url = '/vending/employees/imports/'.$preview['uuid'].'/apply';
        $changed = $this->postJson($url, ['confirmed' => true, 'preview_hash' => $preview['preview_hash']])
            ->assertConflict()->assertJsonPath('stale', true)->json('preview');
        $this->assertSame('Cambio concurrente', $employee->fresh()->full_name);
        $this->assertSame('A', $employee->fresh()->status);
        $this->assertDatabaseMissing('employees', ['employee_number' => 'NEW']);
        $this->assertNotSame($preview['preview_hash'], $changed['preview_hash']);
        $this->postJson($url, ['confirmed' => true, 'preview_hash' => $preview['preview_hash']])->assertConflict();
        $this->postJson($url, ['preview_hash' => $changed['preview_hash']])->assertUnprocessable();
        $this->postJson($url, ['confirmed' => true, 'preview_hash' => $changed['preview_hash']])->assertOk();
    }

    public function test_mysql_ordered_snapshot_blocks_source_ownership_changes_without_partial_writes(): void
    {
        $employee = $this->employee(['employee_number' => 'EDIT', 'source' => EmployeeSource::MANUAL, 'full_name' => 'Antes']);
        $preview = $this->mysqlOrderedPreview($this->upload("employee_number,full_name,status\nEDIT,Después,B\nNEW,Nuevo,A\n")->assertCreated()->json());
        $employee->update(['source' => EmployeeSource::FORTIA]);
        $this->postJson('/vending/employees/imports/'.$preview['uuid'].'/apply', [
            'confirmed' => true, 'preview_hash' => $preview['preview_hash'],
        ])->assertConflict()->assertJsonPath('stale', true)->assertJsonPath('preview.summary.conflicts', 1);
        $this->assertSame(EmployeeSource::FORTIA, $employee->fresh()->source);
        $this->assertSame('Antes', $employee->fresh()->full_name);
        $this->assertDatabaseMissing('employees', ['employee_number' => 'NEW']);
    }

    public function test_preview_ignores_unrelated_timestamps_but_preserves_hash_confirmation(): void
    {
        $employee = $this->employee(['employee_number' => 'EDIT', 'source' => EmployeeSource::MANUAL, 'full_name' => 'Antes']);
        $preview = $this->mysqlOrderedPreview($this->upload("employee_number,full_name,status\nEDIT,Después,A\n")->assertCreated()->json());
        \Illuminate\Support\Facades\DB::table('employees')->where('id', $employee->id)->update([
            'updated_at' => now()->addSecond(), 'source_synced_at' => now()->addSecond(),
        ]);
        $refreshed = $this->getJson('/vending/employees/imports/'.$preview['uuid'])->assertOk()->json();
        $this->assertSame($preview['preview_hash'], $refreshed['preview_hash']);
        $url = '/vending/employees/imports/'.$preview['uuid'].'/apply';
        $this->postJson($url, ['confirmed' => true, 'preview_hash' => str_repeat('0', 64)])->assertConflict();
        $this->assertSame('Antes', $employee->fresh()->full_name);
        $this->postJson($url, ['confirmed' => true, 'preview_hash' => $refreshed['preview_hash']])->assertOk();
    }

    public function test_change_comparison_preserves_value_types_and_detects_missing_or_extra_fields(): void
    {
        $compare = new \ReflectionMethod(EmployeeImportService::class, 'sameChanges');
        $service = app(EmployeeImportService::class);
        $expected = ['full_name' => ['before' => null, 'after' => '0']];
        $this->assertTrue($compare->invoke($service, $expected, ['full_name' => ['after' => '0', 'before' => null]]));
        foreach ([
            ['full_name' => ['before' => '', 'after' => '0']],
            ['full_name' => ['before' => null, 'after' => 0]],
            ['full_name' => ['after' => '0']],
            ['full_name' => ['before' => null, 'after' => '0', 'extra' => null]],
            ['full_name' => ['before' => null, 'after' => '0'], 'status' => []],
            [],
        ] as $changed) {
            $this->assertFalse($compare->invoke($service, $expected, $changed));
        }
    }

    public function test_2502_row_preview_has_bounded_pages_full_counters_and_no_employee_writes(): void
    {
        config(['employees.import.preview_per_page' => 50, 'employees.import.max_rows' => 5000]);
        $csv = "employee_number,full_name,status\n";
        for ($i = 0; $i < 2502; $i++) {
            $csv .= sprintf('%05d,Sintético %d,A', $i, $i)."\n";
        }
        $preview = $this->upload($csv)->assertCreated()->json();
        $this->assertSame(2502, $preview['summary']['total_rows']);
        $this->assertSame(2502, $preview['summary']['valid_new']);
        $this->assertSame(2502, $preview['rows']['total']);
        $this->assertSame(51, $preview['rows']['last_page']);
        $this->assertCount(50, $preview['rows']['data']);
        $url = '/vending/employees/imports/'.$preview['uuid'];
        $second = $this->getJson($url.'?page=2')->assertOk()->json();
        $last = $this->getJson($url.'?page=51')->assertOk()->json();
        $this->assertSame('00050', $second['rows']['data'][0]['employee_number']);
        $this->assertCount(2, $last['rows']['data']);
        $this->assertSame($preview['summary'], $last['summary']);
        $this->assertSame($preview['preview_hash'], $last['preview_hash']);
        config(['employees.import.preview_per_page' => 2502]);
        $bounded = $this->getJson($url)->assertOk()->json();
        $this->assertCount(100, $bounded['rows']['data']);
        $this->assertSame(2502, $bounded['summary']['total_rows']);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseHas('employee_import_runs', ['uuid' => $preview['uuid'], 'status' => 'PREVIEW']);
    }

    private function mysqlOrderedPreview(array $preview): array
    {
        // Reproduce the observed native MySQL JSON key order in SQLite test storage,
        // before issuing the snapshot that the operator confirms. No value is changed.
        $run = EmployeeImportRun::where('uuid', $preview['uuid'])->firstOrFail();
        foreach ($run->rows()->get() as $row) {
            $changes = $row->changes;
            foreach ($changes as &$pair) {
                uksort($pair, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
            }
            unset($pair);
            uksort($changes, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
            \Illuminate\Support\Facades\DB::table('employee_import_rows')->where('id', $row->id)->update([
                'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            ]);
        }

        return $this->getJson('/vending/employees/imports/'.$preview['uuid'])->assertOk()->json();
    }

    private function xlsx(string $rows, array $extra = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vending-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString('xl/styles.xml', '<styleSheet><numFmts><numFmt numFmtId="164" formatCode="00000"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>CLA_TRAB</t></is></c><c r="B1" t="inlineStr"><is><t>NOMBRE</t></is></c><c r="C1" t="inlineStr"><is><t>ESTATUS_TRABAJADOR</t></is></c></row>'.$rows.'</sheetData></worksheet>');
        foreach ($extra as $name => $value) {
            $zip->addFromString($name, $value);
        }
        $zip->close();
        try {
            return UploadedFile::fake()->createWithContent('employees.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
