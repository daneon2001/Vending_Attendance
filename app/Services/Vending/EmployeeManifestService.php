<?php

namespace App\Services\Vending;

use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\VendingMachine;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class EmployeeManifestService
{
    public function __construct(
        private readonly ManifestHashService $hashes,
        private readonly EmployeeManifestVersionService $versions,
    ) {}

    public function snapshot(VendingMachine $machine, DateTimeInterface|string|null $timestamp = null): array
    {
        $generatedAt = $timestamp instanceof DateTimeInterface
            ? Carbon::instance($timestamp)
            : Carbon::parse($timestamp ?? 'now');
        $employees = [];

        $resolved = $this->versions->resolveCurrentState(
            $machine,
            function (VendingMachine $locked) use ($generatedAt, &$employees): string {
                $employees = $this->effectiveEntries($locked, $generatedAt);

                return $this->hashes->hash([
                    'machine_uuid' => $locked->uuid,
                    'employees' => $employees,
                ]);
            },
        );
        $currentMachine = $resolved['machine'];
        $content = [
            'manifest_type' => 'EMPLOYEES',
            'manifest_version' => (int) $currentMachine->employee_manifest_version,
            'machine_uuid' => $currentMachine->uuid,
            'employees' => $employees,
        ];

        return array_merge($content, [
            'manifest_hash' => $this->hashes->hash($content),
            'generated_at' => $generatedAt->copy()->utc()->toIso8601String(),
            'server_time' => $generatedAt->copy()->utc()->toIso8601String(),
        ]);
    }

    private function effectiveEntries(VendingMachine $machine, Carbon $at): array
    {
        return EmployeeMachineAssignment::query()
            ->select([
                'id', 'uuid', 'employee_id', 'vending_machine_id', 'assignment_type',
                'valid_from', 'valid_until', 'attendance_allowed', 'enrollment_allowed',
                'maintenance_allowed',
            ])
            ->where('vending_machine_id', $machine->getKey())
            ->active()
            ->effectiveAt($at)
            ->withRelevantPermission()
            ->whereHas('employee', fn ($query) => $query->activeForVending())
            ->with(['employee' => fn ($query) => $query->select([
                'id', 'employee_number', 'fortia_employee_id', 'name', 'last_name', 'second_last_name', 'full_name', 'status',
            ])])
            ->orderBy('employee_id')
            ->orderBy('uuid')
            ->get()
            ->map(fn (EmployeeMachineAssignment $assignment): array => $this->entry($assignment))
            ->all();
    }

    private function entry(EmployeeMachineAssignment $assignment): array
    {
        /** @var Employee $employee */
        $employee = $assignment->employee;
        $name = trim((string) ($employee->full_name ?: implode(' ', array_filter([
            $employee->name,
            $employee->last_name,
            $employee->second_last_name,
        ]))));

        return [
            'employee_id' => (string) $employee->getKey(),
            'employee_number' => $employee->visibleEmployeeKey(),
            'name' => $name,
            'assignment' => [
                'uuid' => $assignment->uuid,
                'type' => $assignment->assignment_type->value,
                'valid_from' => $assignment->valid_from->copy()->utc()->toIso8601String(),
                'valid_until' => $assignment->valid_until?->copy()->utc()->toIso8601String(),
                'attendance_allowed' => $assignment->attendance_allowed,
                'enrollment_allowed' => $assignment->enrollment_allowed,
                'maintenance_allowed' => $assignment->maintenance_allowed,
            ],
        ];
    }
}
