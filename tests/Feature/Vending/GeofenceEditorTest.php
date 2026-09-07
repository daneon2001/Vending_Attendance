<?php

namespace Tests\Feature\Vending;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SybiVendingSourceRecord;
use App\Models\User;
use App\Services\Vending\DeviceManifestAckService;
use App\Services\Vending\DeviceManifestStatusService;
use App\Services\Vending\MachineConfigurationManifestService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class GeofenceEditorTest extends VendingDeviceApiTestCase
{
    private function operator(array $actions = ['view', 'geofence']): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'geofence-'.$user->id]);
        $role->permissions()->attach(Permission::query()->where('module', 'vending_machines')->whereIn('action', $actions)->pluck('id'));
        $user->roles()->attach($role);

        return $user;
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'center_latitude' => 19.4326, 'center_longitude' => -99.1332,
            'radius_m' => 80, 'minimum_acceptable_accuracy_m' => 25,
            'tolerance_m' => 5, 'status' => 'ACTIVE', 'source' => 'MANUAL',
        ], $overrides);
    }

    public function test_view_returns_existing_limits_and_source_without_writing(): void
    {
        $machine = $this->machine();
        $this->geofence($machine);
        $count = AuditLog::count();
        $this->actingAs($this->operator(['view']))->get(route('vending-machines.show', $machine))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('machine.geofences', 1)
            ->where('geofenceEditor.limits.radius_m.min', 1)
            ->where('geofenceEditor.limits.radius_m.max', 100000)
            ->where('geofenceEditor.limits.tolerance_m.min', 0));
        $this->assertSame($count, AuditLog::count());
    }

    public function test_create_and_edit_preserve_source_history_and_emit_audit(): void
    {
        $user = $this->operator();
        $machine = $this->machine();
        $machine->update(['source' => 'SYBI', 'sybi_id' => 'EDITOR-SOURCE']);
        $source = SybiVendingSourceRecord::query()->create([
            'sybi_id' => 'EDITOR-SOURCE', 'identificador_vending' => 'EDITOR-135',
            'name' => 'Synthetic source', 'latitude' => 19.4326, 'longitude' => -99.1332,
            'source_status' => 'PRESENT', 'validation_status' => 'READY',
            'promoted_vending_machine_id' => $machine->id,
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'payload_hash' => hash('sha256', 'synthetic-geofence-editor-source'),
        ]);
        $sourceBefore = $source->fresh()->getAttributes();
        $this->actingAs($user)->get(route('vending-machines.show', $machine))->assertInertia(fn (Assert $page) => $page
            ->where('geofenceEditor.source_location.latitude', '19.4326000')
            ->where('geofenceEditor.source_location.longitude', '-99.1332000'));
        $machine = $machine->fresh();
        $version = $machine->config_version;
        $this->actingAs($user)->post(route('vending-machines.geofences.store', $machine),
            $this->input(['expected_config_version' => $version]))->assertSessionHasNoErrors()->assertRedirect();
        $old = $machine->geofences()->firstOrFail();
        $this->assertSame($version + 1, $machine->fresh()->config_version);
        $this->post(route('vending-machines.geofences.store', $machine), $this->input([
            'center_latitude' => 19.433, 'radius_m' => 120, 'expected_config_version' => $version + 1,
            'latitude' => 0, 'longitude' => 0, 'sybi_id' => 'FORGED', 'address_line' => 'FORGED',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(2, $machine->geofences()->count());
        $this->assertSame('SUPERSEDED', $old->fresh()->status->value);
        $this->assertSame(80, $old->fresh()->radius_m);
        $this->assertSame('19.4326000', $machine->fresh()->latitude);
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
        $this->assertDatabaseHas('audit_logs', ['event' => 'geofence.created', 'actor_user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'geofence.activated', 'actor_user_id' => $user->id]);
    }

    public function test_stale_preview_is_rejected_without_partial_draft_or_config_write(): void
    {
        $machine = $this->machine();
        $this->geofence($machine);
        $version = $machine->fresh()->config_version;
        $this->actingAs($this->operator())->postJson(route('vending-machines.geofences.store', $machine),
            $this->input(['expected_config_version' => $version - 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('expected_config_version');
        $this->assertSame(1, $machine->geofences()->count());
        $this->assertSame($version, $machine->fresh()->config_version);
    }

    public function test_view_only_and_unrelated_parent_cannot_mutate(): void
    {
        $machine = $this->machine();
        $other = $this->machine();
        $geofence = $this->geofence($machine);
        $version = $machine->fresh()->config_version;
        $this->actingAs($this->operator(['view']))->post(route('vending-machines.geofences.store', $machine), $this->input())->assertForbidden();
        foreach (['activate', 'deactivate'] as $action) {
            $this->patch(route('vending-machines.geofences.'.$action, [$machine, $geofence]))->assertForbidden();
            $this->actingAs($this->operator())->patch(route('vending-machines.geofences.'.$action, [$other, $geofence]))->assertNotFound();
            $this->actingAs($this->operator(['view']));
        }
        $this->patch(route('vending-machines.location.verify', $machine))->assertForbidden();
        $this->assertSame($version, $machine->fresh()->config_version);
        $this->assertSame('ACTIVE', $geofence->fresh()->status->value);
    }

    public function test_existing_coordinate_radius_accuracy_and_tolerance_limits_are_enforced(): void
    {
        $machine = $this->machine();
        $this->actingAs($this->operator());
        foreach ([
            ['radius_m' => 0], ['radius_m' => 100001], ['radius_m' => 1.5],
            ['center_latitude' => 91], ['center_longitude' => -181],
            ['center_latitude' => 0, 'center_longitude' => 0],
            ['minimum_acceptable_accuracy_m' => -1], ['minimum_acceptable_accuracy_m' => 100001],
            ['tolerance_m' => -1], ['tolerance_m' => 100001],
        ] as $invalid) {
            $this->postJson(route('vending-machines.geofences.store', $machine), $this->input($invalid))->assertUnprocessable();
        }
        $this->assertSame(0, $machine->geofences()->count());
    }

    public function test_draft_is_not_sent_until_activation_and_deactivation_preserves_geometry(): void
    {
        $machine = $this->machine();
        $this->actingAs($this->operator())->post(route('vending-machines.geofences.store', $machine), $this->input(['status' => 'DRAFT']))->assertSessionHasNoErrors();
        $draft = $machine->geofences()->firstOrFail();
        $this->assertSame(1, $machine->fresh()->config_version);
        $this->patch(route('vending-machines.geofences.activate', [$machine, $draft]), ['expected_config_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, $machine->fresh()->config_version);
        $this->patch(route('vending-machines.geofences.deactivate', [$machine, $draft]), ['expected_config_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(3, $machine->fresh()->config_version);
        $this->assertSame('INACTIVE', $draft->fresh()->status->value);
        $this->assertSame(80, $draft->fresh()->radius_m);
        $this->assertNull($machine->activeGeofence()->first());
        $this->assertDatabaseHas('audit_logs', ['event' => 'geofence.deactivated', 'auditable_id' => $draft->id]);
    }

    public function test_nullable_tolerance_is_zero_without_a_database_error(): void
    {
        $machine = $this->machine();
        $this->actingAs($this->operator())->postJson(route('vending-machines.geofences.store', $machine),
            $this->input(['tolerance_m' => null, 'minimum_acceptable_accuracy_m' => null]))->assertRedirect();
        $geofence = $machine->geofences()->firstOrFail();
        $this->assertSame(0.0, $geofence->tolerance_m);
        $this->assertNull($geofence->minimum_acceptable_accuracy_m);
    }

    public function test_deactivation_is_idempotent_and_superseded_version_cannot_reactivate(): void
    {
        $machine = $this->machine();
        $old = $this->geofence($machine);
        $current = $this->geofence($machine, ['radius_m' => 100]);
        $this->actingAs($this->operator())->patchJson(route('vending-machines.geofences.activate', [$machine, $old]))->assertUnprocessable();
        $version = $machine->fresh()->config_version;
        $this->patch(route('vending-machines.geofences.deactivate', [$machine, $current]))->assertSessionHasNoErrors();
        $this->patch(route('vending-machines.geofences.deactivate', [$machine, $current]))->assertSessionHasNoErrors();
        $this->assertSame($version + 1, $machine->fresh()->config_version);
        $this->assertSame(2, $machine->geofences()->count());
    }

    public function test_location_verification_uses_existing_fields_and_audit_actor(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 7)->setTime(12, 0));
        $machine = $this->machine();
        $machine->update(['coordinates_verified' => false, 'coordinates_verified_at' => null]);
        $version = $machine->fresh()->config_version;
        $user = $this->operator();
        $this->actingAs($user)->patch(route('vending-machines.location.verify', $machine), [
            'expected_config_version' => $version, 'confirmed' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($machine->fresh()->coordinates_verified);
        $this->assertTrue($machine->fresh()->coordinates_verified_at->equalTo(now()));
        $this->assertSame($version + 1, $machine->fresh()->config_version);
        $this->assertDatabaseHas('audit_logs', ['event' => 'vending_machine.location_verified', 'actor_user_id' => $user->id, 'auditable_id' => $machine->id]);
    }

    public function test_verification_rejects_missing_confirmation_invalid_source_and_stale_version(): void
    {
        $machine = $this->machine();
        $this->actingAs($this->operator());
        $this->patchJson(route('vending-machines.location.verify', $machine), [])->assertUnprocessable();
        $this->patchJson(route('vending-machines.location.verify', $machine), ['confirmed' => true, 'expected_config_version' => 0])->assertUnprocessable();
        $machine->update(['latitude' => null, 'longitude' => null, 'coordinates_verified' => false]);
        $this->patchJson(route('vending-machines.location.verify', $machine), [
            'confirmed' => true, 'expected_config_version' => $machine->fresh()->config_version,
        ])->assertUnprocessable();
        $this->assertFalse($machine->fresh()->coordinates_verified);
    }

    public function test_editor_change_propagates_pending_fetch_ack_without_new_protocol(): void
    {
        $machine = $this->machine();
        $this->geofence($machine);
        $device = $this->provisionedDevice($machine)['device'];
        $snapshots = app(MachineConfigurationManifestService::class);
        $acks = app(DeviceManifestAckService::class);
        $statuses = app(DeviceManifestStatusService::class);
        $ack = function (array $snapshot) use ($acks, $device): void {
            $this->assertTrue($acks->acknowledge($device->fresh(), [
                'manifest_type' => 'CONFIGURATION', 'manifest_version' => $snapshot['manifest_version'],
                'manifest_hash' => $snapshot['manifest_hash'], 'status' => 'APPLIED',
            ])['ok']);
        };
        $before = $snapshots->snapshot($device);
        $ack($before);
        $this->assertSame('SYNCED', $statuses->status($device->fresh())['configuration']['state']);
        $this->actingAs($this->operator())->post(route('vending-machines.geofences.store', $machine),
            $this->input(['expected_config_version' => $machine->fresh()->config_version]))->assertSessionHasNoErrors();
        $this->assertSame('PENDING', $statuses->status($device->fresh())['configuration']['state']);
        $after = $snapshots->snapshot($device->fresh());
        $this->assertSame(80, $after['geofence']['radius_m']);
        $this->assertSame($before['manifest_version'], $device->fresh()->config_version_applied);
        $ack($after);
        $this->assertSame('SYNCED', $statuses->status($device->fresh())['configuration']['state']);
    }
}
