<?php

namespace Tests\Feature\Vending;

use App\Enums\Vending\MobileVersionStatus;
use App\Models\Device;
use App\Models\MobileRelease;
use App\Models\MobileReleasePolicy;
use App\Models\VendingMachine;
use App\Services\Vending\DeviceFleetHealthService;
use App\Services\Vending\MobileVersionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceFleetHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_derived_from_lifecycle_heartbeat_and_operational_signals(): void
    {
        config([
            'vending.device.health.degraded_after_seconds' => 180,
            'vending.device.health.offline_after_seconds' => 600,
            'vending.device.health.pending_events_count' => 100,
        ]);
        $machine = $this->machine();
        $device = $this->device($machine, ['last_heartbeat_at' => now(), 'last_seen_at' => now()]);
        $service = app(DeviceFleetHealthService::class);

        $this->assertSame('ONLINE', $service->evaluate($device->fresh())['status']);

        $device->forceFill(['last_heartbeat_at' => now()->subSeconds(240)])->save();
        $this->assertSame('DEGRADED', $service->evaluate($device->fresh())['status']);

        $device->forceFill(['last_heartbeat_at' => now()->subSeconds(700)])->save();
        $this->assertSame('OFFLINE', $service->evaluate($device->fresh())['status']);

        $device->forceFill(['status' => 'SUSPENDED'])->save();
        $this->assertSame('SUSPENDED', $service->evaluate($device->fresh())['status']);
    }

    public function test_manifest_lag_and_outbox_pressure_degrade_an_active_device(): void
    {
        $machine = $this->machine(['config_version' => 8, 'employee_manifest_version' => 5]);
        $device = $this->device($machine, [
            'config_version_applied' => 4,
            'employee_manifest_version_applied' => 5,
            'pending_events_count' => 100,
            'last_heartbeat_at' => now(),
            'last_seen_at' => now(),
        ]);
        $health = app(DeviceFleetHealthService::class)->evaluate($device->fresh());

        $this->assertSame('DEGRADED', $health['status']);
        $this->assertContains('MANIFEST_STALE', $health['reasons']);
        $this->assertContains('OUTBOX_PRESSURE', $health['reasons']);
        $this->assertSame(8, $health['manifest']['configuration']['server_version']);
        $this->assertSame(4, $health['manifest']['configuration']['applied_version']);
    }

    public function test_mobile_version_policy_distinguishes_current_available_required_and_unsupported(): void
    {
        $machine = $this->machine();
        $device = $this->device($machine, ['app_version' => '2.0.0', 'app_build_number' => 20]);
        $minimum = $this->release('1.5.0', 15);
        $current = $this->release('2.0.0', 20);
        $recommended = $this->release('2.1.0', 21, ['mandatory' => false, 'rollout_percentage' => 100]);
        $policy = MobileReleasePolicy::query()->create([
            'platform' => 'ANDROID', 'channel' => 'PRODUCTION',
            'minimum_release_id' => $minimum->id,
            'current_release_id' => $current->id,
            'recommended_release_id' => $recommended->id,
        ])->load(['minimumRelease', 'currentRelease', 'recommendedRelease']);
        $service = app(MobileVersionPolicyService::class);

        $this->assertSame(MobileVersionStatus::UPDATE_AVAILABLE->value, $service->evaluate($device, $policy)['status']);
        $recommended->forceFill(['mandatory' => true])->save();
        $policy->load(['minimumRelease', 'currentRelease', 'recommendedRelease']);
        $this->assertSame(MobileVersionStatus::UPDATE_REQUIRED->value, $service->evaluate($device, $policy)['status']);

        $device->forceFill(['app_version' => '1.0.0', 'app_build_number' => 10])->save();
        $this->assertSame(MobileVersionStatus::UNSUPPORTED->value, $service->evaluate($device, $policy)['status']);

        $device->forceFill(['app_version' => '2.1.0', 'app_build_number' => 21])->save();
        $this->assertSame(MobileVersionStatus::CURRENT->value, $service->evaluate($device, $policy)['status']);
    }

    public function test_rollout_bucket_is_stable_and_honors_boundaries(): void
    {
        $device = $this->device($this->machine());
        $service = app(MobileVersionPolicyService::class);
        $release = $this->release('3.0.0', 30, ['rollout_percentage' => 0]);
        $this->assertFalse($service->eligibleForRollout($device, $release));
        $release->rollout_percentage = 100;
        $this->assertTrue($service->eligibleForRollout($device, $release));
        $release->rollout_percentage = 25;
        $this->assertSame(
            $service->eligibleForRollout($device, $release),
            $service->eligibleForRollout($device, $release),
        );
        $release->rollout_percentage = 100;
        $release->targets()->create(['target_type' => 'GROUP', 'target_value' => 'other-group']);
        $release->load('targets');
        $this->assertFalse($service->eligibleForRollout($device, $release));
        $device->forceFill(['release_group' => 'other-group'])->save();
        $this->assertTrue($service->eligibleForRollout($device, $release));
    }

    private function machine(array $attributes = []): VendingMachine
    {
        return VendingMachine::query()->create(array_merge([
            'machine_code' => 'FLT-'.Str::random(8), 'latitude' => 19.4, 'longitude' => -99.1,
            'coordinate_source' => 'MANUAL', 'coordinates_verified' => true,
            'timezone' => 'America/Mexico_City', 'status' => 'ACTIVE',
        ], $attributes));
    }

    private function device(VendingMachine $machine, array $attributes = []): Device
    {
        return Device::query()->forceCreate(array_merge([
            'device_serial' => 'FLT-'.Str::random(12), 'shared_secret' => '',
            'credential_secret' => Str::random(64), 'credential_version' => 1,
            'credential_issued_at' => now(), 'vending_machine_id' => $machine->id,
            'status' => 'ACTIVE', 'is_active' => true, 'platform' => 'android',
            'platform_version' => '15', 'app_version' => '1.0.0',
            'release_channel' => 'PRODUCTION',
            'config_version_applied' => 1, 'employee_manifest_version_applied' => 1,
        ], $attributes));
    }

    private function release(string $version, int $build, array $attributes = []): MobileRelease
    {
        return MobileRelease::query()->create(array_merge([
            'platform' => 'ANDROID', 'channel' => 'PRODUCTION', 'version' => $version,
            'build_number' => $build, 'status' => 'PUBLISHED', 'artifact_url' => 'https://releases.example.test/app.apk',
            'artifact_sha256' => str_repeat('a', 64), 'rollout_percentage' => 100, 'released_at' => now(),
        ], $attributes));
    }
}
