<?php

namespace Tests\Feature\Vending;

use App\Http\Middleware\EnsurePermission;
use App\Models\Device;
use App\Models\DeviceNonce;
use App\Models\MobileRelease;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetMaintenanceAndUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(EnsurePermission::class);
    }

    public function test_nonce_pruning_is_bounded_and_not_part_of_hmac_hot_path(): void
    {
        $device = $this->device($this->machine());
        foreach (range(1, 105) as $index) {
            DeviceNonce::query()->create([
                'device_id' => $device->id, 'nonce' => (string) Str::uuid(),
                'seen_at' => now()->subHour(), 'expires_at' => now()->subMinute(),
            ]);
        }
        DeviceNonce::query()->create([
            'device_id' => $device->id, 'nonce' => (string) Str::uuid(),
            'seen_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);

        $this->artisan('device-nonces:prune --batch=100')->assertSuccessful();
        $this->assertDatabaseCount('device_nonces', 6);
        $this->artisan('device-nonces:prune --batch=100')->assertSuccessful();
        $this->assertDatabaseCount('device_nonces', 1);
    }

    public function test_fleet_dashboard_and_registry_render_without_per_device_manifest_queries(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 20) as $index) {
            $machine = $this->machine(['machine_code' => sprintf('FLEET-%03d', $index)]);
            $this->device($machine, ['last_heartbeat_at' => now(), 'last_seen_at' => now()]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('vending-devices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendingFleet/Devices')
                ->has('devices.data', 20));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(25, $queryCount, "Registry executed {$queryCount} queries for 20 devices.");
        $this->actingAs($user)->get(route('vending-fleet.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VendingFleet/Dashboard')
                ->where('kpis.machines_total', 20)
                ->where('kpis.devices_online', 20));
    }

    public function test_release_endpoint_rejects_insecure_artifact_and_accepts_checksum_metadata(): void
    {
        $user = User::factory()->create();
        $payload = [
            'platform' => 'ANDROID', 'channel' => 'PILOT', 'version' => '1.2.0',
            'target_type' => 'CHANNEL', 'target_value' => null,
            'build_number' => 12, 'status' => 'PUBLISHED', 'minimum_os' => '10',
            'artifact_url' => 'http://insecure.example.test/app.apk',
            'artifact_sha256' => str_repeat('a', 64), 'mandatory' => false,
            'rollout_percentage' => 5, 'notes' => 'Pilot metadata only.',
        ];

        $this->actingAs($user)->post(route('vending-releases.store'), $payload)
            ->assertSessionHasErrors('artifact_url');
        $payload['artifact_url'] = 'https://user:secret@releases.example.test/app.apk?token=secret';
        $this->actingAs($user)->post(route('vending-releases.store'), $payload)
            ->assertSessionHasErrors('artifact_url');
        $payload['artifact_url'] = 'https://releases.example.test/app.apk';
        $this->actingAs($user)->post(route('vending-releases.store'), $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mobile_releases', [
            'platform' => 'ANDROID', 'channel' => 'PILOT', 'version' => '1.2.0',
            'artifact_sha256' => str_repeat('a', 64),
        ]);
    }

    public function test_device_release_channel_is_explicit_and_audited(): void
    {
        $user = User::factory()->create();
        $machine = $this->machine();
        $device = $this->device($machine);

        $this->actingAs($user)->patch(route('vending-machines.devices.release-channel', [$machine, $device]), [
            'release_channel' => 'PILOT',
        ])->assertRedirect();

        $this->assertSame('PILOT', $device->fresh()->release_channel);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'device.release_channel_updated',
            'auditable_id' => $device->id,
        ]);
    }

    public function test_release_policy_rejects_cross_channel_release_and_accepts_same_scope(): void
    {
        $user = User::factory()->create();
        $release = MobileRelease::query()->create([
            'platform' => 'ANDROID', 'channel' => 'PILOT', 'version' => '2.0.0',
            'build_number' => 20, 'status' => 'PUBLISHED',
            'artifact_url' => 'https://releases.example.test/app.apk',
            'artifact_sha256' => str_repeat('b', 64), 'rollout_percentage' => 25,
            'released_at' => now(),
        ]);
        $payload = [
            'platform' => 'IOS', 'channel' => 'PILOT',
            'current_release_id' => $release->id,
            'recommended_release_id' => null,
            'minimum_release_id' => null,
        ];

        $this->actingAs($user)->put(route('vending-releases.policy.update'), $payload)
            ->assertSessionHasErrors('current_release_id');

        $payload['platform'] = 'ANDROID';
        $this->actingAs($user)->put(route('vending-releases.policy.update'), $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mobile_release_policies', [
            'platform' => 'ANDROID', 'channel' => 'PILOT', 'current_release_id' => $release->id,
        ]);
    }

    public function test_release_targets_support_specific_devices_and_groups(): void
    {
        $user = User::factory()->create();
        $machine = $this->machine();
        $device = $this->device($machine, ['release_group' => 'north-pilot']);
        $release = MobileRelease::query()->create([
            'platform' => 'ANDROID', 'channel' => 'PRODUCTION', 'version' => '3.0.0',
            'build_number' => 30, 'status' => 'PUBLISHED',
            'artifact_url' => 'https://releases.example.test/app.apk',
            'artifact_sha256' => str_repeat('c', 64), 'rollout_percentage' => 100,
            'released_at' => now(),
        ]);

        $this->actingAs($user)->post(route('vending-releases.targets.store', $release), [
            'target_type' => 'GROUP', 'target_value' => 'north-pilot',
        ])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('vending-releases.targets.store', $release), [
            'target_type' => 'DEVICE', 'target_value' => $device->uuid,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('mobile_release_targets', 2);
        $this->assertTrue(app(\App\Services\Vending\MobileVersionPolicyService::class)
            ->eligibleForRollout($device, $release->load('targets')));
    }

    public function test_published_release_can_be_blocked_and_is_no_longer_eligible(): void
    {
        $user = User::factory()->create();
        $device = $this->device($this->machine());
        $release = MobileRelease::query()->create([
            'platform' => 'ANDROID', 'channel' => 'PRODUCTION', 'version' => '3.1.0',
            'build_number' => 31, 'status' => 'PUBLISHED',
            'artifact_url' => 'https://releases.example.test/app.apk',
            'artifact_sha256' => str_repeat('d', 64), 'rollout_percentage' => 100,
            'released_at' => now(),
        ]);

        $this->actingAs($user)->patch(route('vending-releases.block', $release))->assertRedirect();

        $release->refresh();
        $this->assertSame('BLOCKED', $release->status->value);
        $this->assertSame(0, $release->rollout_percentage);
        $this->assertFalse(app(\App\Services\Vending\MobileVersionPolicyService::class)
            ->eligibleForRollout($device, $release));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'mobile_release.blocked', 'auditable_id' => $release->id,
        ]);
    }

    public function test_registry_filters_manifest_lag_without_fetching_another_machine(): void
    {
        $user = User::factory()->create();
        $syncedMachine = $this->machine(['machine_code' => 'FILTER-SYNCED']);
        $pendingMachine = $this->machine(['machine_code' => 'FILTER-PENDING', 'config_version' => 3]);
        $staleMachine = $this->machine(['machine_code' => 'FILTER-STALE', 'config_version' => 4]);
        $this->device($syncedMachine);
        $this->device($pendingMachine, ['config_version_applied' => 1]);
        $this->device($staleMachine, ['config_version_applied' => 1]);

        $this->actingAs($user)->get(route('vending-devices.index', ['sync_state' => 'PENDING']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.machine.machine_code', 'FILTER-PENDING'));
        $this->actingAs($user)->get(route('vending-devices.index', ['sync_state' => 'STALE']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('devices.data', 1)
                ->where('devices.data.0.machine.machine_code', 'FILTER-STALE'));
    }

    public function test_fleet_routes_require_human_authentication_and_permission(): void
    {
        $this->withMiddleware(EnsurePermission::class);
        $this->get(route('vending-fleet.dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())
            ->get(route('vending-fleet.dashboard'))
            ->assertForbidden();
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
}
