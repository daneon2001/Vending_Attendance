<?php

namespace Tests\Feature\Support;

use App\Models\SupportIntegration;
use App\Models\SupportTicket;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportEvidenceService;
use App\Services\Support\SupportIntegrationTokens;
use App\Services\Support\SupportTicketService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportSecurityRegressionTest extends VendingDeviceApiTestCase
{
    private function report(): array
    {
        return ['client_operation_uuid' => (string) Str::uuid(), 'category' => 'OTHER', 'title' => 'Prueba segura', 'description' => 'Fixture aislado'];
    }

    public function test_ticket_read_scope_does_not_expose_evidence_metadata_through_timeline_or_changes(): void
    {
        Storage::fake('support_private');
        $machine = $this->machine();
        $device = $this->provisionedDevice($machine)['device'];
        $actor = SupportActor::device($device);
        $uuid = app(SupportTicketService::class)->create($actor, $this->report())['ticket']['uuid'];
        $ticket = SupportTicket::where('uuid', $uuid)->firstOrFail();
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $files = app(SupportEvidenceService::class);
        $evidence = $files->initiate($actor, $ticket, ['client_operation_uuid' => (string) Str::uuid(),
            'mime' => 'image/png', 'size_bytes' => strlen($bytes), 'upload_sha256' => hash('sha256', $bytes)])['evidence'];
        $files->upload($actor, $ticket, $evidence['uuid'], $bytes);
        $service = SupportIntegration::create(['uuid' => (string) Str::uuid(), 'system_key' => 'READ_ONLY_TEST', 'name' => 'Test', 'active' => true]);
        $service->machines()->attach($machine);
        $token = app(SupportIntegrationTokens::class)->issue($service, 'Ticket read only', ['support.tickets.read'], now()->addHour());
        $this->withToken($token->plainTextToken);
        foreach (['/api/v1/support/integration/tickets/'.$uuid, '/api/v1/support/integration/changes'] as $path) {
            $response = $this->getJson($path)->assertOk();
            $event = collect($response->json('events'))->firstWhere('kind', 'support.evidence.created');
            $this->assertNotNull($event);
            foreach (['evidence_uuid', 'sha256', 'mime', 'size_bytes'] as $key) {
                $this->assertArrayNotHasKey($key, $event['metadata'], 'Evidence metadata requires its own scope on every surface.');
            }
        }
    }

    public function test_device_create_receipt_cannot_escape_current_machine_scope_after_reassignment(): void
    {
        $fixture = $this->provisionedDevice($this->machine());
        $data = $this->report();
        $path = '/api/v1/device/support/tickets';
        $this->signedDeviceRequest('POST', $path, $data, $fixture['device'], $fixture['credential'])->assertCreated();
        $other = $this->machine();
        $fixture['device']->update(['vending_machine_id' => $other->id]);
        $this->signedDeviceRequest('POST', $path, $data, $fixture['device']->fresh(), $fixture['credential'])->assertConflict();
        $this->assertDatabaseCount('support_tickets', 1);
    }

    public function test_delayed_report_cannot_be_attributed_to_a_different_machine_with_same_device_identity(): void
    {
        $machine = $this->machine();
        $fixture = $this->provisionedDevice($machine);
        $data = [...$this->report(), 'captured_machine_uuid' => $machine->uuid];
        $fixture['device']->update(['vending_machine_id' => $this->machine()->id]);
        $this->signedDeviceRequest('POST', '/api/v1/device/support/tickets', $data, $fixture['device']->fresh(), $fixture['credential'])->assertConflict();
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_location_requires_explicit_report_time_and_retries_remain_stable_after_three_minutes(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-07T12:00:00Z'));
        try {
            $fixture = $this->provisionedDevice($this->machine());
            $data = [...$this->report(), 'location' => [
                'latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5,
                'captured_at' => now('UTC')->toISOString(),
            ]];
            $path = '/api/v1/device/support/tickets';
            $this->signedDeviceRequest('POST', $path, $data, $fixture['device'], $fixture['credential'])
                ->assertUnprocessable()->assertJsonValidationErrors('reported_at');
            $this->assertDatabaseCount('support_tickets', 0);
            $data['reported_at'] = now('UTC')->toISOString();
            $uuid = $this->signedDeviceRequest('POST', $path, $data, $fixture['device'], $fixture['credential'])
                ->assertCreated()->json('ticket.uuid');
            $this->travel(3)->minutes();
            $this->signedDeviceRequest('POST', $path, $data, $fixture['device'], $fixture['credential'])
                ->assertCreated()->assertJsonPath('ticket.uuid', $uuid);
            $this->assertDatabaseCount('support_tickets', 1);
        } finally {
            $this->travelBack();
        }
    }

    public function test_delayed_verification_cannot_change_machine_attribution_after_reassignment(): void
    {
        $machine = $this->machine();
        $fixture = $this->provisionedDevice($machine);
        $data = ['client_operation_uuid' => (string) Str::uuid(), 'captured_machine_uuid' => $machine->uuid,
            'started_at' => now('UTC')->toISOString(), 'completed_at' => now('UTC')->toISOString(), 'checks' => []];
        $fixture['device']->update(['vending_machine_id' => $this->machine()->id]);
        $this->signedDeviceRequest('POST', '/api/v1/device/support/verifications', $data, $fixture['device']->fresh(), $fixture['credential'])
            ->assertConflict()->assertJsonPath('code', 'MACHINE_CHANGED');
        $this->assertDatabaseCount('support_verifications', 0);
    }

    public function test_reserved_sybi_machine_identifier_is_not_confused_with_source_primary_id(): void
    {
        // Synthetic test fixture only: no live machine or Device is activated/created.
        $machine = $this->machine();
        $machine->update(['source' => 'SYBI', 'sybi_id' => '683', 'machine_code' => '7']);
        $fixture = $this->provisionedDevice($machine);
        $data = ['client_operation_uuid' => (string) Str::uuid(), 'captured_machine_uuid' => $machine->uuid,
            'started_at' => now('UTC')->toISOString(), 'completed_at' => now('UTC')->toISOString(), 'checks' => []];
        $this->signedDeviceRequest('POST', '/api/v1/device/support/verifications', $data, $fixture['device'], $fixture['credential'])
            ->assertForbidden();
        $this->assertDatabaseCount('support_verifications', 0);
        $this->assertDatabaseCount('support_tickets', 0);
    }
}
