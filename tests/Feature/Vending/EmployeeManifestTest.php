<?php

namespace Tests\Feature\Vending;

use App\Services\Vending\EmployeeManifestService;
use App\Services\Vending\MachineAssignmentService;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class EmployeeManifestTest extends VendingDeviceApiTestCase
{
    public function test_snapshot_contains_only_effective_authorized_employees_and_minimal_data(): void
    {
        $machine = $this->machine('MANIFEST-SCOPE');
        $otherMachine = $this->machine('MANIFEST-OTHER');
        $assigned = $this->employee([
            'full_name' => 'Persona Autorizada',
            'rfc' => 'AAAA000000AAA',
            'curp' => 'AAAA000000HDFBBB00',
            'imss_number' => 'SECRET-NSS',
            'email_company' => 'private@example.test',
        ]);
        $temporary = $this->employee(['full_name' => 'Cobertura Temporal']);
        $other = $this->employee();
        $inactive = $this->employee(['status' => 'B']);
        $revoked = $this->employee();
        $expired = $this->employee();
        $future = $this->employee();
        $permissionless = $this->employee();

        $this->assignment($machine, $assigned, ['enrollment_allowed' => true]);
        $this->assignment($machine, $temporary, [
            'assignment_type' => 'TEMPORARY',
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addHour(),
            'attendance_allowed' => false,
            'maintenance_allowed' => true,
        ]);
        $this->assignment($otherMachine, $other);
        $this->assignment($machine, $inactive);
        $revokedAssignment = $this->assignment($machine, $revoked);
        app(MachineAssignmentService::class)->revoke($revokedAssignment, null, 'Revoked for test');
        $this->assignment($machine, $expired, [
            'valid_from' => now()->subHours(2),
            'valid_until' => now()->subHour(),
        ]);
        $this->assignment($machine, $future, ['valid_from' => now()->addHour()]);
        $this->assignment($machine, $permissionless, [
            'attendance_allowed' => false,
            'enrollment_allowed' => false,
            'maintenance_allowed' => false,
        ]);

        $snapshot = app(EmployeeManifestService::class)->snapshot($machine);
        $employeeIds = collect($snapshot['employees'])->pluck('employee_id')->all();

        $this->assertSame([(string) $assigned->id, (string) $temporary->id], $employeeIds);
        $this->assertSame([
            'employee_id', 'employee_number', 'name', 'assignment',
        ], array_keys($snapshot['employees'][0]));
        $this->assertSame([
            'uuid', 'type', 'valid_from', 'valid_until',
            'attendance_allowed', 'enrollment_allowed', 'maintenance_allowed',
        ], array_keys($snapshot['employees'][0]['assignment']));
        $this->assertTrue($snapshot['employees'][0]['assignment']['attendance_allowed']);
        $this->assertTrue($snapshot['employees'][0]['assignment']['enrollment_allowed']);
        $this->assertSame('TEMPORARY', $snapshot['employees'][1]['assignment']['type']);
        $this->assertNotNull($snapshot['employees'][1]['assignment']['valid_until']);

        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['rfc', 'curp', 'imss_number', 'email_company', 'base_location_id', 'check_scope', 'metadata'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $serialized);
        }
        $this->assertStringNotContainsString('private@example.test', $serialized);
        $this->assertStringNotContainsString('SECRET-NSS', $serialized);
    }

    public function test_assignment_changes_bump_monotonically_but_irrelevant_update_does_not(): void
    {
        $machine = $this->machine('MANIFEST-VERSION');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);

        $this->assertSame(2, $machine->refresh()->employee_manifest_version);

        $assignment->update(['source' => 'IMPORT']);
        $this->assertSame(2, $machine->refresh()->employee_manifest_version);

        $assignment->update(['enrollment_allowed' => true]);
        $this->assertSame(3, $machine->refresh()->employee_manifest_version);

        app(MachineAssignmentService::class)->revoke($assignment->fresh(), null, 'End assignment');
        $this->assertSame(4, $machine->refresh()->employee_manifest_version);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'employee_manifest.version_changed',
            'auditable_id' => $machine->id,
        ]);
    }

    public function test_employee_deactivation_and_reactivation_change_desired_state_without_deleting_history(): void
    {
        $machine = $this->machine('MANIFEST-EMPLOYEE-STATUS');
        $employee = $this->employee(['full_name' => 'Employee Status']);
        $assignment = $this->assignment($machine, $employee);
        $service = app(EmployeeManifestService::class);

        $this->assertCount(1, $service->snapshot($machine)['employees']);
        $version = $machine->refresh()->employee_manifest_version;

        $employee->update(['status' => 'B']);
        $this->assertGreaterThan($version, $machine->refresh()->employee_manifest_version);
        $this->assertCount(0, $service->snapshot($machine)['employees']);
        $this->assertDatabaseHas('employee_machine_assignments', ['id' => $assignment->id, 'status' => 'ACTIVE']);

        $version = $machine->refresh()->employee_manifest_version;
        $employee->update(['status' => 'A']);
        $this->assertGreaterThan($version, $machine->refresh()->employee_manifest_version);
        $this->assertCount(1, $service->snapshot($machine)['employees']);
    }

    public function test_hash_is_stable_and_validity_boundaries_advance_version(): void
    {
        $machine = $this->machine('MANIFEST-HASH');
        $employee = $this->employee();
        $this->assignment($machine, $employee, [
            'assignment_type' => 'TEMPORARY',
            'valid_from' => now()->addHour(),
            'valid_until' => now()->addHours(2),
        ]);
        $service = app(EmployeeManifestService::class);

        $before = $service->snapshot($machine, now());
        $same = $service->snapshot($machine, now()->addMinutes(10));
        $effective = $service->snapshot($machine, now()->addMinutes(90));
        $expired = $service->snapshot($machine, now()->addHours(3));

        $this->assertSame($before['manifest_hash'], $same['manifest_hash']);
        $this->assertSame($before['manifest_version'], $same['manifest_version']);
        $this->assertNotSame($before['manifest_hash'], $effective['manifest_hash']);
        $this->assertGreaterThan($before['manifest_version'], $effective['manifest_version']);
        $this->assertCount(1, $effective['employees']);
        $this->assertGreaterThan($effective['manifest_version'], $expired['manifest_version']);
        $this->assertCount(0, $expired['employees']);
    }

    public function test_serialized_assignment_writes_and_revocation_do_not_lose_versions(): void
    {
        $machine = $this->machine('MANIFEST-LOCKING');
        $first = $this->assignment($machine, $this->employee());
        $this->assignment($machine, $this->employee());

        $this->assertSame(3, $machine->refresh()->employee_manifest_version);
        $beforeRevocation = app(EmployeeManifestService::class)->snapshot($machine);
        $this->assertCount(2, $beforeRevocation['employees']);

        app(MachineAssignmentService::class)->revoke($first, null, 'Concurrent boundary simulation');
        $afterRevocation = app(EmployeeManifestService::class)->snapshot($machine);

        $this->assertSame(4, $afterRevocation['manifest_version']);
        $this->assertCount(1, $afterRevocation['employees']);
        $this->assertNotSame($beforeRevocation['manifest_hash'], $afterRevocation['manifest_hash']);
    }
}
