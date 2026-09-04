<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Vending\VendingMachineStatus;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\MachineGeofence;
use App\Models\VendingMachine;
use App\Services\Vending\DeviceProvisioningTokenService;
use App\Services\Vending\MachineAssignmentService;
use App\Services\Vending\MachineGeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class VendingDeviceApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'vending.device.rate_limits.provision_per_minute' => 1000,
            'vending.device.rate_limits.bootstrap_per_minute' => 1000,
            'vending.device.rate_limits.heartbeat_per_minute' => 1000,
            'vending.manifests.rate_limits.status_per_minute' => 1000,
            'vending.manifests.rate_limits.download_per_minute' => 1000,
            'vending.manifests.rate_limits.ack_per_minute' => 1000,
            'vending.attendance.rate_limits.single_per_minute' => 1000,
            'vending.attendance.rate_limits.batch_per_minute' => 1000,
            'vending.attendance.batch_max_events' => 100,
            'vending.attendance.future_tolerance_seconds' => 300,
            'vending.attendance.minimum_captured_year' => 2000,
            'onprem.hmac_tolerance_seconds' => 300,
            'onprem.nonce_ttl_seconds' => 600,
        ]);
        RateLimiter::clear('vending-device-provision:127.0.0.1');
    }

    protected function machine(?string $code = null): VendingMachine
    {
        return VendingMachine::query()->create([
            'machine_code' => $code ?? 'DEV-'.fake()->unique()->numerify('#####'),
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'coordinate_source' => 'MANUAL',
            'coordinates_verified' => true,
            'timezone' => 'America/Mexico_City',
            'status' => VendingMachineStatus::ACTIVE->value,
        ]);
    }

    protected function provisioningToken(VendingMachine $machine, $expiresAt = null): array
    {
        return app(DeviceProvisioningTokenService::class)->create($machine, null, $expiresAt);
    }

    protected function employee(array $attributes = []): Employee
    {
        return Employee::query()->create(array_merge([
            'fortia_employee_id' => fake()->unique()->numberBetween(10000, 99999),
            'full_name' => fake()->name(),
            'status' => 'A',
        ], $attributes));
    }

    protected function assignment(VendingMachine $machine, Employee $employee, array $attributes = []): EmployeeMachineAssignment
    {
        return app(MachineAssignmentService::class)->create($machine, array_merge([
            'employee_id' => $employee->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => now()->subMinute(),
            'valid_until' => null,
            'attendance_allowed' => true,
            'enrollment_allowed' => false,
            'maintenance_allowed' => false,
            'source' => 'TEST',
        ], $attributes));
    }

    protected function geofence(VendingMachine $machine, array $attributes = []): MachineGeofence
    {
        return app(MachineGeofenceService::class)->create($machine, array_merge([
            'center_latitude' => 19.4326,
            'center_longitude' => -99.1332,
            'radius_m' => 50,
            'minimum_acceptable_accuracy_m' => 30,
            'tolerance_m' => 10,
            'status' => 'ACTIVE',
            'source' => 'TEST',
        ], $attributes));
    }

    protected function attendancePayload(
        VendingMachine $machine,
        Employee $employee,
        EmployeeMachineAssignment $assignment,
        ?MachineGeofence $geofence = null,
        array $overrides = [],
    ): array {
        $machine = $machine->fresh();
        $geofence ??= $machine->activeGeofence()->first();

        return array_replace_recursive([
            'event_uuid' => (string) Str::uuid(),
            'employee_id' => (string) $employee->getKey(),
            'event_type' => 'CHECK_IN',
            'captured_at' => now()->utc()->toIso8601String(),
            'employee_manifest_version' => (int) $machine->employee_manifest_version,
            'configuration_version' => (int) $machine->config_version,
            'assignment_uuid' => $assignment->uuid,
            'device_timezone' => $machine->timezone,
            'location' => [
                'latitude' => 19.4326,
                'longitude' => -99.1332,
                'accuracy_m' => 5,
            ],
            'geofence' => $geofence ? [
                'version' => $geofence->version,
                'edge_result' => 'INSIDE',
            ] : null,
        ], $overrides);
    }

    /**
     * @return array{device:Device,credential:string,response:\Illuminate\Testing\TestResponse}
     */
    protected function provisionedDevice(VendingMachine $machine, ?string $serial = null): array
    {
        $token = $this->provisioningToken($machine);
        $response = $this->postJson('/api/v1/device/provision', [
            'provisioning_token' => $token['plain_token'],
            'device_serial' => $serial ?? 'SER-'.fake()->unique()->numerify('######'),
            'platform' => 'android',
            'platform_version' => '15',
            'app_version' => '1.0.0',
            'hardware_model' => 'Test Terminal',
        ])->assertCreated();

        return [
            'device' => Device::query()->where('uuid', $response->json('device.uuid'))->firstOrFail(),
            'credential' => (string) $response->json('credentials.credential'),
            'response' => $response,
        ];
    }

    protected function signedDeviceRequest(
        string $method,
        string $path,
        array $payload,
        Device $device,
        string $credential,
        ?int $timestamp = null,
        ?string $nonce = null,
        ?string $signedRequestTarget = null,
        ?array $signedPayload = null,
    ) {
        $method = strtoupper($method);
        $timestamp ??= now()->timestamp;
        $nonce ??= (string) Str::uuid();
        $requestTarget = $path;
        if (in_array($method, ['GET', 'HEAD'], true) && $payload !== []) {
            $requestTarget .= '?'.http_build_query($payload);
        }
        $body = in_array($method, ['GET', 'HEAD'], true)
            ? ''
            : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signedBody = in_array($method, ['GET', 'HEAD'], true)
            ? ''
            : json_encode($signedPayload ?? $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $canonical = $method."\n".($signedRequestTarget ?? $requestTarget)."\n".$timestamp."\n".$nonce."\n".hash('sha256', $signedBody);
        $signature = base64_encode(hash_hmac('sha256', $canonical, $credential, true));

        return $this->call(
            $method,
            $requestTarget,
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_DEVICE_ID' => $device->uuid,
                'HTTP_X_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_NONCE' => $nonce,
                'HTTP_X_SIGNATURE' => $signature,
            ],
            $body,
        );
    }
}
