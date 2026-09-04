<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\MachineGeofence;
use App\Models\Role;
use App\Models\User;
use App\Models\VendingAttendanceEvent;
use App\Models\VendingMachine;
use Database\Seeders\VendingDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;

class VendingDemoCleanupCommand extends Command
{
    protected $signature = 'vending:demo-cleanup {--force : Skip the interactive local confirmation}';

    protected $description = 'Remove only the deterministic local vending demo dataset';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('vending:demo-cleanup is restricted to local and testing environments.');
        }

        if (! $this->option('force') && ! $this->confirm('Remove only VM-DEMO-* records and their demo dependencies?')) {
            $this->components->info('No data was removed.');

            return self::SUCCESS;
        }

        $counts = DB::transaction(fn (): array => $this->removeDemoData(), 3);

        foreach ($counts as $label => $count) {
            $this->line(sprintf('%s: %d', $label, $count));
        }

        return self::SUCCESS;
    }

    /** @return array<string,int> */
    private function removeDemoData(): array
    {
        $machineIds = VendingMachine::query()->whereIn('machine_code', array_keys(VendingDemoSeeder::MACHINE_UUIDS))->pluck('id');
        $employeeIds = Employee::query()->whereIn('fortia_employee_id', array_values(VendingDemoSeeder::EMPLOYEE_NUMBERS))->pluck('id');
        $deviceIds = Device::query()->whereIn('uuid', array_values(VendingDemoSeeder::DEVICE_UUIDS))->pluck('id');
        $assignmentIds = EmployeeMachineAssignment::query()->whereIn('uuid', array_values(VendingDemoSeeder::ASSIGNMENT_UUIDS))->pluck('id');
        $geofenceIds = MachineGeofence::query()->whereIn('uuid', array_values(VendingDemoSeeder::GEOFENCE_UUIDS))->pluck('id');
        $eventIds = VendingAttendanceEvent::query()->whereIn('event_uuid', VendingDemoSeeder::EVENT_UUIDS)->pluck('id');

        $counts = [
            'attendance_events' => DB::table('vending_attendance_events')->whereIn('id', $eventIds)->delete(),
            'device_attendance_metrics' => DB::table('device_attendance_metrics')->whereIn('device_id', $deviceIds)->delete(),
            'device_manifest_states' => DB::table('device_manifest_states')->whereIn('device_id', $deviceIds)->delete(),
            'device_nonces' => DB::table('device_nonces')->whereIn('device_id', $deviceIds)->delete(),
            'provisioning_tokens' => DB::table('device_provisioning_tokens')->whereIn('vending_machine_id', $machineIds)->delete(),
        ];

        $auditTargets = [
            (new VendingMachine)->getMorphClass() => $machineIds,
            (new EmployeeMachineAssignment)->getMorphClass() => $assignmentIds,
            (new MachineGeofence)->getMorphClass() => $geofenceIds,
            (new Device)->getMorphClass() => $deviceIds,
            (new VendingAttendanceEvent)->getMorphClass() => $eventIds,
        ];
        $counts['audit_logs'] = 0;
        foreach ($auditTargets as $type => $ids) {
            $counts['audit_logs'] += DB::table('audit_logs')
                ->where('auditable_type', $type)
                ->whereIn('auditable_id', $ids)
                ->delete();
        }

        $counts['devices'] = DB::table('devices')->whereIn('id', $deviceIds)->delete();
        $counts['assignments'] = DB::table('employee_machine_assignments')->whereIn('id', $assignmentIds)->delete();
        $counts['geofences'] = DB::table('machine_geofences')->whereIn('id', $geofenceIds)->delete();
        $counts['machines'] = DB::table('vending_machines')->whereIn('id', $machineIds)->delete();
        $counts['employees'] = DB::table('employees')->whereIn('id', $employeeIds)->delete();

        $demoUser = User::query()->where('email', VendingDemoSeeder::ADMIN_EMAIL)->first();
        $counts['admin_user'] = $demoUser ? (int) $demoUser->delete() : 0;
        $demoRole = Role::query()->where('name', VendingDemoSeeder::ADMIN_ROLE)->first();
        $counts['admin_role'] = $demoRole && ! $demoRole->users()->exists() ? (int) $demoRole->delete() : 0;

        return $counts;
    }
}
