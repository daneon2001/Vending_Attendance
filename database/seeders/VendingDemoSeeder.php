<?php

namespace Database\Seeders;

use App\Actions\SyncPermissionCatalog;
use App\Enums\DeviceStatus;
use App\Enums\Employees\EmployeeSource;
use App\Enums\Vending\AssignmentStatus;
use App\Enums\Vending\AssignmentType;
use App\Enums\Vending\CoordinateSource;
use App\Enums\Vending\GeofenceStatus;
use App\Enums\Vending\ManifestAckStatus;
use App\Enums\Vending\ManifestType;
use App\Enums\Vending\VendingMachineStatus;
use App\Enums\Vending\VendingCatalogSource;
use App\Models\Device;
use App\Models\DeviceManifestState;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\MachineGeofence;
use App\Models\Role;
use App\Models\User;
use App\Models\VendingAttendanceEvent;
use App\Models\VendingMachine;
use App\Services\Vending\DeviceCredentialService;
use App\Services\Vending\EmployeeManifestService;
use App\Services\Vending\MachineAssignmentService;
use App\Services\Vending\MachineConfigurationManifestService;
use App\Services\Vending\MachineGeofenceService;
use App\Services\Vending\VendingAttendanceReceiverService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class VendingDemoSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin.vending.local@example.test';

    public const ADMIN_ROLE = 'Vending Demo Admin';

    public const MACHINE_UUIDS = [
        'VM-DEMO-001' => '00000000-0000-4000-8000-000000000001',
        'VM-DEMO-002' => '00000000-0000-4000-8000-000000000002',
        'VM-DEMO-003' => '00000000-0000-4000-8000-000000000003',
    ];

    /** The legacy Employee projection requires numeric Fortia identifiers. */
    public const EMPLOYEE_NUMBERS = [
        'DEMO1001' => 990001001,
        'DEMO1002' => 990001002,
        'DEMO1003' => 990001003,
        'DEMO1004' => 990001004,
        'DEMO1005' => 990001005,
    ];

    public const GEOFENCE_UUIDS = [
        'VM-DEMO-001' => '10000000-0000-4000-8000-000000000001',
        'VM-DEMO-002' => '10000000-0000-4000-8000-000000000002',
        'VM-DEMO-003' => '10000000-0000-4000-8000-000000000003',
    ];

    public const ASSIGNMENT_UUIDS = [
        'VM1_PRIMARY' => '20000000-0000-4000-8000-000000000001',
        'VM1_SUPERVISOR' => '20000000-0000-4000-8000-000000000002',
        'VM2_PRIMARY' => '20000000-0000-4000-8000-000000000003',
        'VM2_TEMPORARY' => '20000000-0000-4000-8000-000000000004',
        'VM3_TECHNICIAN' => '20000000-0000-4000-8000-000000000005',
        'VM1_REVOKED' => '20000000-0000-4000-8000-000000000006',
    ];

    public const DEVICE_UUIDS = [
        'VM-DEMO-001' => '30000000-0000-4000-8000-000000000001',
        'VM-DEMO-002' => '30000000-0000-4000-8000-000000000002',
    ];

    public const EVENT_UUIDS = [
        '40000000-0000-4000-8000-000000000001',
        '40000000-0000-4000-8000-000000000002',
        '40000000-0000-4000-8000-000000000003',
        '40000000-0000-4000-8000-000000000004',
        '40000000-0000-4000-8000-000000000005',
    ];

    public function run(): void
    {
        $this->ensureSafeEnvironment();

        DB::transaction(function (): void {
            $administrator = $this->resolveAdministrator();
            $machines = $this->seedMachines();
            $employees = $this->seedEmployees();
            $assignments = $this->seedAssignments($machines, $employees, $administrator);
            $geofences = $this->seedGeofences($machines, $administrator);
            $devices = $this->seedDevices($machines);

            $this->synchronizeDemoDevice($devices['VM-DEMO-001'], $machines['VM-DEMO-001']);
            $this->seedAttendanceEvents(
                $devices['VM-DEMO-001'],
                $machines['VM-DEMO-001'],
                $employees,
                $assignments,
                $geofences['VM-DEMO-001'],
            );
        }, 3);

        $this->command?->info('Vending demo dataset is ready. No credential or password was printed.');
    }

    private function ensureSafeEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('VendingDemoSeeder is restricted to local and testing environments.');
        }
    }

    private function resolveAdministrator(): User
    {
        $requiredActions = ['view', 'create', 'update', 'assign', 'geofence', 'manage'];
        $administrator = User::query()->with('roles.permissions')->get()->first(
            fn (User $user): bool => collect($requiredActions)->every(
                fn (string $action): bool => $user->hasPermission('vending_machines', $action),
            ),
        );

        if ($administrator) {
            return $administrator;
        }

        $password = config('vending.demo.admin_password');
        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException(
                'No local vending administrator exists. Configure VENDING_DEMO_ADMIN_PASSWORD and run the seeder again.',
            );
        }

        $administrator = User::query()->updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'Administrador Vending Demo',
                'password' => $password,
                'estatus' => true,
            ],
        );
        $administrator->forceFill(['email_verified_at' => now()])->save();
        $role = Role::query()->updateOrCreate(
            ['name' => self::ADMIN_ROLE],
            ['description' => 'Acceso local al módulo vending demo.', 'is_system' => false],
        );
        $permissionIds = SyncPermissionCatalog::resolveIds([
            'vending_machines' => $requiredActions,
        ]);
        $role->permissions()->sync($permissionIds);
        $administrator->roles()->syncWithoutDetaching([$role->getKey()]);

        return $administrator;
    }

    /** @return array<string,VendingMachine> */
    private function seedMachines(): array
    {
        $definitions = [
            'VM-DEMO-001' => [
                'name' => 'Vending Demo Centro',
                'address_line' => 'Calle Demo 101',
                'neighborhood' => 'Colonia Centro de Pruebas',
                'locality' => 'Ciudad de México',
                'municipality' => 'Cuauhtémoc',
                'state' => 'Ciudad de México',
                'postal_code' => '06000',
                'latitude' => 19.432608,
                'longitude' => -99.133209,
                'status' => VendingMachineStatus::ACTIVE,
                'default_geofence_radius_m' => 50,
            ],
            'VM-DEMO-002' => [
                'name' => 'Vending Demo Naucalpan',
                'address_line' => 'Avenida Ejemplo 202',
                'neighborhood' => 'Colonia Industrial Demo',
                'locality' => 'Naucalpan',
                'municipality' => 'Naucalpan de Juárez',
                'state' => 'Estado de México',
                'postal_code' => '53000',
                'latitude' => 19.478500,
                'longitude' => -99.239600,
                'status' => VendingMachineStatus::ACTIVE,
                'default_geofence_radius_m' => 75,
            ],
            'VM-DEMO-003' => [
                'name' => 'Vending Demo Toluca',
                'address_line' => 'Circuito Ficticio 303',
                'neighborhood' => 'Parque de Demostración',
                'locality' => 'Toluca',
                'municipality' => 'Toluca',
                'state' => 'Estado de México',
                'postal_code' => '50000',
                'latitude' => 19.282600,
                'longitude' => -99.655700,
                'status' => VendingMachineStatus::MAINTENANCE,
                'default_geofence_radius_m' => 60,
            ],
        ];
        $machines = [];

        foreach ($definitions as $code => $attributes) {
            $machine = VendingMachine::query()->firstOrNew(['machine_code' => $code]);
            if (! $machine->exists) {
                $machine->uuid = self::MACHINE_UUIDS[$code];
                $machine->config_version = 1;
            }
            $machine->fill(array_merge($attributes, [
                'country' => 'MX',
                'source' => VendingCatalogSource::DEMO,
                'coordinate_source' => CoordinateSource::MANUAL,
                'coordinates_verified' => true,
                'coordinates_verified_at' => Carbon::parse('2026-09-04 12:00:00', 'America/Mexico_City'),
                'timezone' => 'America/Mexico_City',
                'metadata' => ['demo' => true, 'dataset' => 'VENDING_DEMO_V1'],
            ]));
            $machine->save();
            $machines[$code] = $machine->fresh();
        }

        return $machines;
    }

    /** @return array<string,Employee> */
    private function seedEmployees(): array
    {
        $names = [
            'DEMO1001' => ['Empleado Demo Uno', 'Empleado Demo', 'Uno'],
            'DEMO1002' => ['Empleado Demo Dos', 'Empleado Demo', 'Dos'],
            'DEMO1003' => ['Empleado Demo Tres', 'Empleado Demo', 'Tres'],
            'DEMO1004' => ['Supervisor Demo', 'Supervisor', 'Demo'],
            'DEMO1005' => ['Técnico Demo', 'Técnico', 'Demo'],
        ];
        $employees = [];

        foreach (self::EMPLOYEE_NUMBERS as $alias => $number) {
            [$fullName, $name, $lastName] = $names[$alias];
            $employees[$alias] = Employee::query()->updateOrCreate(
                ['fortia_employee_id' => $number],
                [
                    'employee_number' => $alias,
                    'source' => EmployeeSource::DEMO,
                    'source_external_id' => (string) $number,
                    'name' => $name,
                    'last_name' => $lastName,
                    'second_last_name' => null,
                    'full_name' => $fullName,
                    'status' => 'A',
                    'company_id' => null,
                    'company_name' => 'VENDING DEMO LOCAL',
                    'base_location_id' => null,
                    'base_location_name' => null,
                    'department_id' => null,
                    'department_name' => null,
                    'rfc' => null,
                    'imss_number' => null,
                    'curp' => null,
                    'email_company' => null,
                    'has_fingerprint' => false,
                    'has_face_enrollment' => false,
                ],
            );
        }

        return $employees;
    }

    /** @return array<string,EmployeeMachineAssignment> */
    private function seedAssignments(array $machines, array $employees, User $administrator): array
    {
        $now = now();
        $definitions = [
            'VM1_PRIMARY' => ['VM-DEMO-001', 'DEMO1001', AssignmentType::PRIMARY, $now->copy()->subDays(30), null, true, true, false],
            'VM1_SUPERVISOR' => ['VM-DEMO-001', 'DEMO1004', AssignmentType::SUPERVISOR, $now->copy()->subDays(30), null, true, false, true],
            'VM2_PRIMARY' => ['VM-DEMO-002', 'DEMO1002', AssignmentType::PRIMARY, $now->copy()->subDays(30), null, true, false, false],
            'VM2_TEMPORARY' => ['VM-DEMO-002', 'DEMO1003', AssignmentType::TEMPORARY, $now->copy()->subDay(), $now->copy()->addDays(7), true, false, false],
            'VM3_TECHNICIAN' => ['VM-DEMO-003', 'DEMO1005', AssignmentType::TECHNICIAN, $now->copy()->subDays(30), null, false, false, true],
            'VM1_REVOKED' => ['VM-DEMO-001', 'DEMO1003', AssignmentType::SUBSTITUTE, $now->copy()->subDays(20), $now->copy()->subDays(10), true, false, false],
        ];
        $service = app(MachineAssignmentService::class);
        $assignments = [];

        foreach ($definitions as $key => [$machineCode, $employeeAlias, $type, $from, $until, $attendance, $enrollment, $maintenance]) {
            $assignment = EmployeeMachineAssignment::query()->where('uuid', self::ASSIGNMENT_UUIDS[$key])->first();
            if (! $assignment) {
                $assignment = $service->create($machines[$machineCode], [
                    'uuid' => self::ASSIGNMENT_UUIDS[$key],
                    'employee_id' => $employees[$employeeAlias]->getKey(),
                    'assignment_type' => $type,
                    'valid_from' => $from,
                    'valid_until' => $until,
                    'attendance_allowed' => $attendance,
                    'enrollment_allowed' => $enrollment,
                    'maintenance_allowed' => $maintenance,
                    'status' => AssignmentStatus::ACTIVE,
                    'source' => 'DEMO',
                    'metadata' => ['demo' => true, 'alias' => $employeeAlias],
                ], $administrator->getKey());
            }

            if ($key === 'VM1_REVOKED' && $assignment->status !== AssignmentStatus::REVOKED) {
                $assignment = $service->revoke($assignment, $administrator->getKey(), 'Histórico demo revocado');
            }
            $assignments[$key] = $assignment;
        }

        return $assignments;
    }

    /** @return array<string,MachineGeofence> */
    private function seedGeofences(array $machines, User $administrator): array
    {
        $definitions = [
            'VM-DEMO-001' => [50, GeofenceStatus::ACTIVE],
            'VM-DEMO-002' => [75, GeofenceStatus::ACTIVE],
            'VM-DEMO-003' => [60, GeofenceStatus::DRAFT],
        ];
        $service = app(MachineGeofenceService::class);
        $geofences = [];

        foreach ($definitions as $code => [$radius, $status]) {
            $geofence = MachineGeofence::query()->where('uuid', self::GEOFENCE_UUIDS[$code])->first();
            if (! $geofence) {
                $geofence = $service->create($machines[$code], [
                    'uuid' => self::GEOFENCE_UUIDS[$code],
                    'center_latitude' => $machines[$code]->latitude,
                    'center_longitude' => $machines[$code]->longitude,
                    'radius_m' => $radius,
                    'minimum_acceptable_accuracy_m' => 30,
                    'tolerance_m' => 10,
                    'valid_from' => now()->subDays(30),
                    'status' => $status->value,
                    'source' => 'DEMO',
                    'metadata' => ['demo' => true],
                ], $administrator->getKey());
            }
            $geofences[$code] = $geofence;
        }

        return $geofences;
    }

    /** @return array<string,Device> */
    private function seedDevices(array $machines): array
    {
        $active = Device::query()->firstOrNew(['uuid' => self::DEVICE_UUIDS['VM-DEMO-001']]);
        $active->forceFill([
            'device_serial' => 'VM-DEMO-TERM-001',
            'device_name' => 'Vending Demo Terminal 001',
            'vending_machine_id' => $machines['VM-DEMO-001']->getKey(),
            'platform' => 'android',
            'platform_version' => '14',
            'app_version' => '0.1.0-demo',
            'hardware_model' => 'Demo Terminal',
            'status' => DeviceStatus::ACTIVE,
            'provisioned_at' => $active->provisioned_at ?? now()->subDay(),
            'activated_at' => $active->activated_at ?? now()->subDay(),
            'last_seen_at' => now()->subMinutes(2),
            'last_heartbeat_at' => now()->subMinutes(2),
            'is_active' => true,
            'credential_revoked_at' => null,
            'clock_drift_seconds' => 4,
            'metadata' => ['demo' => true, 'sync_scenario' => 'SYNCED'],
        ])->save();

        if (blank($active->credential_secret)) {
            app(DeviceCredentialService::class)->issue($active);
            $active->refresh();
        }

        $pending = Device::query()->firstOrNew(['uuid' => self::DEVICE_UUIDS['VM-DEMO-002']]);
        $pending->forceFill([
            'device_serial' => 'VM-DEMO-TERM-002',
            'device_name' => 'Vending Demo Terminal 002 — pendiente',
            'vending_machine_id' => $machines['VM-DEMO-002']->getKey(),
            'platform' => 'android',
            'platform_version' => '14',
            'app_version' => '0.1.0-demo',
            'hardware_model' => 'Demo Terminal',
            'status' => DeviceStatus::PENDING,
            'is_active' => false,
            'config_version_applied' => null,
            'employee_manifest_version_applied' => null,
            'credential_secret' => null,
            'credential_revoked_at' => null,
            'last_seen_at' => null,
            'last_heartbeat_at' => null,
            'metadata' => ['demo' => true, 'sync_scenario' => 'PENDING'],
        ])->save();

        return ['VM-DEMO-001' => $active->fresh(), 'VM-DEMO-002' => $pending->fresh()];
    }

    private function synchronizeDemoDevice(Device $device, VendingMachine $machine): void
    {
        $configuration = app(MachineConfigurationManifestService::class)->snapshot($device);
        $employees = app(EmployeeManifestService::class)->snapshot($machine);
        $acknowledgedAt = now()->subMinute();

        $device->forceFill([
            'config_version_applied' => $configuration['manifest_version'],
            'employee_manifest_version_applied' => $employees['manifest_version'],
        ])->save();

        foreach ([
            ManifestType::CONFIGURATION->value => $configuration,
            ManifestType::EMPLOYEES->value => $employees,
        ] as $type => $manifest) {
            DeviceManifestState::query()->updateOrCreate(
                ['device_id' => $device->getKey(), 'manifest_type' => $type],
                [
                    'applied_version' => $manifest['manifest_version'],
                    'applied_hash' => $manifest['manifest_hash'],
                    'last_ack_status' => ManifestAckStatus::APPLIED,
                    'last_ack_version' => $manifest['manifest_version'],
                    'last_ack_hash' => $manifest['manifest_hash'],
                    'last_ack_at' => $acknowledgedAt,
                    'reported_applied_at' => $acknowledgedAt,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ],
            );
        }
    }

    private function seedAttendanceEvents(
        Device $device,
        VendingMachine $machine,
        array $employees,
        array $assignments,
        MachineGeofence $geofence,
    ): void {
        $now = now()->utc();
        $definitions = [
            [self::EVENT_UUIDS[0], 'DEMO1001', 'VM1_PRIMARY', 'CHECK_IN', $now->copy()->subMinutes(30), 19.432608, -99.133209, 5, 'INSIDE'],
            [self::EVENT_UUIDS[1], 'DEMO1001', 'VM1_PRIMARY', 'CHECK_OUT', $now->copy()->subMinutes(25), 19.432708, -99.133209, 40, 'UNCERTAIN'],
            [self::EVENT_UUIDS[2], 'DEMO1004', 'VM1_SUPERVISOR', 'CHECK_IN', $now->copy()->subMinutes(20), 19.434608, -99.133209, 5, 'OUTSIDE'],
            [self::EVENT_UUIDS[3], 'DEMO1001', 'VM1_PRIMARY', 'CHECK_IN', $now->copy()->subHours(4), 19.432608, -99.133209, 5, 'INSIDE'],
            [self::EVENT_UUIDS[4], 'DEMO1001', 'VM1_PRIMARY', 'CHECK_OUT', $now->copy()->subDays(3), 19.432608, -99.133209, 8, 'INSIDE'],
        ];
        $receiver = app(VendingAttendanceReceiverService::class);

        foreach ($definitions as [$eventUuid, $employeeAlias, $assignmentKey, $type, $capturedAt, $latitude, $longitude, $accuracy, $edgeResult]) {
            if (VendingAttendanceEvent::query()->where('event_uuid', $eventUuid)->exists()) {
                continue;
            }

            $result = $receiver->receive($device, [
                'event_uuid' => $eventUuid,
                'employee_id' => $employees[$employeeAlias]->getKey(),
                'event_type' => $type,
                'captured_at' => $capturedAt->toIso8601String(),
                'employee_manifest_version' => (int) $machine->fresh()->employee_manifest_version,
                'configuration_version' => (int) $machine->fresh()->config_version,
                'assignment_uuid' => $assignments[$assignmentKey]->uuid,
                'device_timezone' => 'America/Mexico_City',
                'location' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'accuracy_m' => $accuracy,
                ],
                'geofence' => [
                    'version' => (int) $geofence->version,
                    'edge_result' => $edgeResult,
                ],
            ]);

            if (($result['status'] ?? null) !== 'STORED') {
                throw new RuntimeException(sprintf(
                    'Demo attendance event %s was not stored (%s).',
                    $eventUuid,
                    $result['error_code'] ?? $result['status'] ?? 'UNKNOWN',
                ));
            }
        }
    }
}
