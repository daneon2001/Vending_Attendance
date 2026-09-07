<?php

namespace Tests\Feature\Vending;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Employees\Fortia\HttpFortiaEmployeeClient;
use App\Services\Employees\FortiaEmployeeSyncService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class FortiaEmployeeIntegrationTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fortia.sync_driver' => 'http', 'fortia.base_url' => 'https://fortia.example.test',
            'employees.fortia.http_contract_approved' => true, 'employees.fortia.employees_path' => '/employees',
            'employees.fortia.api_token' => 'synthetic-test-token', 'employees.fortia.allow_write' => true,
        ]);
        Http::preventStrayRequests();
    }

    public function test_dry_run_is_write_free_and_second_sync_is_idempotent_minimal_and_no_assignments(): void
    {
        Http::fake(['*' => Http::response(['data' => [$this->record(), $this->record('0002', 'remote2', 'B')]])]);
        $service = app(FortiaEmployeeSyncService::class);
        $beforeAudits = AuditLog::count();
        $result = $service->sync(true);
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['inactive']);
        $this->assertDatabaseCount('employees', 0);
        $this->assertSame($beforeAudits, AuditLog::count());
        $this->artisan('fortia:sync-employees --dry-run')->assertSuccessful();
        $this->assertDatabaseCount('employees', 0);
        $service->sync(false);
        $this->assertSame(2, $service->sync(false)['unchanged']);
        $this->assertDatabaseCount('employees', 2);
        $this->assertDatabaseCount('employee_machine_assignments', 0);
        $employee = Employee::where('employee_number', '0001')->firstOrFail();
        $this->assertNull($employee->rfc);
        $this->assertNull($employee->email_company);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.sync.completed']);
        $this->assertStringNotContainsString('unnecessary-private-data', AuditLog::get()->toJson());
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_duplicates_conflicts_empty_partial_and_explicit_inactive_status(): void
    {
        $service = app(FortiaEmployeeSyncService::class);
        Http::fake(['*' => Http::response(['data' => [$this->record()]])]);
        $service->sync(false);
        $employee = Employee::where('employee_number', '0001')->firstOrFail();
        $machine = $this->machine();
        $this->assignment($machine, $employee);
        $version = $machine->fresh()->employee_manifest_version;
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->assertSame(0, $service->sync(false)['received']);
        $this->assertSame('A', $employee->fresh()->status);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => [$this->record('0001', 'remote1', 'B')]])]);
        $this->assertSame(1, $service->sync(false)['updated']);
        $this->assertSame($version + 1, $machine->fresh()->employee_manifest_version);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => [$this->record('0003', 'r3'), $this->record('0003', 'r4'), $this->record('0001', 'wrong-id')]])]);
        $this->assertSame(3, $service->sync(false)['conflicts']);
        $this->assertDatabaseCount('employees', 1);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => [['employee_number' => 4], 'malformed-row']])]);
        $this->assertSame(2, $service->sync(true)['rejected']);
    }

    public function test_provider_failures_are_safe_and_never_write(): void
    {
        foreach ([401, 403, 422, 500, 503] as $status) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response('secret-provider-body', $status)]);
            try {
                app(FortiaEmployeeSyncService::class)->sync(false);
                $this->fail('Expected safe upstream error.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('FORTIA_HTTP_'.$status, $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('malformed-json')]);
        try {
            app(FortiaEmployeeSyncService::class)->sync(true);
            $this->fail('Expected malformed JSON rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('FORTIA_RESPONSE_CONTRACT', $exception->getMessage());
        }
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('secret-token-in-provider-error'));
        try {
            app(FortiaEmployeeSyncService::class)->sync(false);
            $this->fail('Expected timeout rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('FORTIA_TRANSPORT_FAILED', $exception->getMessage());
        }
        $this->assertDatabaseCount('employees', 0);
        $this->assertStringNotContainsString('secret-', AuditLog::get()->toJson());
    }

    public function test_manual_owned_collision_and_unapproved_contract_and_writes_fail_closed(): void
    {
        $manual = $this->employee(['employee_number' => '0001', 'source' => 'MANUAL', 'full_name' => 'Keep']);
        Http::fake(['*' => Http::response(['data' => [$this->record()]])]);
        $this->assertSame(1, app(FortiaEmployeeSyncService::class)->sync(false)['conflicts']);
        $this->assertSame('Keep', $manual->fresh()->full_name);
        config(['employees.fortia.http_contract_approved' => false]);
        try {
            app(HttpFortiaEmployeeClient::class)->fetch();
            $this->fail('Expected contract gate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('FORTIA_HTTP_CONTRACT_NOT_CONFIGURED', $exception->getMessage());
        }
        config(['employees.fortia.allow_write' => false]);
        $this->expectExceptionMessage('FORTIA_WRITE_DISABLED');
        app(FortiaEmployeeSyncService::class)->sync(false);
    }

    private function record(string $number = '0001', string $id = 'remote1', string $status = 'A'): array
    {
        return ['employee_number' => $number, 'source_external_id' => $id, 'full_name' => 'Synthetic Person', 'status' => $status, 'source_updated_at' => '2026-09-05T12:00:00Z', 'rfc' => 'unnecessary-private-data', 'email_company' => 'unnecessary-private-data'];
    }

    public function test_legacy_facade_keeps_metrics_and_honors_ownership_and_write_gate(): void
    {
        Http::fake(['*' => Http::response(['data' => [$this->record()]])]);
        $service = app(\App\Services\Fortia\FortiaEmployeeService::class);
        $result = $service->syncEmployees();
        $this->assertSame(1, $result['new']);
        $this->assertSame([], $result['changed']);
        $this->assertSame(1, $service->syncEmployees()['unchanged']);
        config(['employees.fortia.allow_write' => false]);
        try {
            $service->syncEmployees();
            $this->fail('Legacy writes must require authorization.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('FORTIA_WRITE_DISABLED', $exception->getMessage());
        }
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('employee_machine_assignments', 0);
    }

    public function test_timestamp_only_updates_do_not_bump_manifest_or_log_unchanged_rows(): void
    {
        Http::fake(['*' => Http::response(['data' => [$this->record()]])]);
        $service = app(FortiaEmployeeSyncService::class);
        $service->sync(false);
        $employee = Employee::where('employee_number', '0001')->firstOrFail();
        $machine = $this->machine();
        $this->assignment($machine, $employee);
        $version = $machine->fresh()->employee_manifest_version;
        $rowAudits = AuditLog::whereIn('event', ['employee.created', 'employee.updated', 'employee.status_changed'])->count();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['data' => [[...$this->record(), 'source_updated_at' => null]]])]);
        $this->assertSame(1, $service->sync(false)['unchanged']);
        $this->assertSame($version, $machine->fresh()->employee_manifest_version);
        $this->assertTrue($employee->source_updated_at->equalTo($employee->fresh()->source_updated_at));
        $this->assertSame($rowAudits, AuditLog::whereIn('event', ['employee.created', 'employee.updated', 'employee.status_changed'])->count());
    }
}
