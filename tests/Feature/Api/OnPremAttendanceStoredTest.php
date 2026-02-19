<?php

namespace Tests\Feature\Api;

class OnPremAttendanceStoredTest extends OnPremApiTestCase
{
    public function test_event_is_stored_with_expected_contract_response(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-001', 'secret-stored');
        $this->seedCollaborator(88001, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '11111111-1111-1111-1111-111111111111',
                    'collaborator_id' => 88001,
                    'punched_at_local' => '2026-02-18T08:02:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T14:02:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                    'quality' => 78,
                    'meta' => ['scanner' => 'S1'],
                ],
            ],
        ];

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('received.0.local_event_id', '11111111-1111-1111-1111-111111111111')
            ->assertJsonPath('received.0.stored', true)
            ->assertJsonPath('received.0.status', 'STORED')
            ->assertJsonPath('received.0.reason', null);

        $remoteId = $response->json('received.0.remote_id');
        $this->assertNotNull($remoteId);

        $this->assertDatabaseHas('attendances_raw', [
            'remote_event_id' => $remoteId,
            'device_serial' => $fixture['device_serial'],
            'local_event_id' => '11111111-1111-1111-1111-111111111111',
            'collaborator_id' => 88001,
            'unit_id' => $fixture['unit_id'],
            'tz' => 'America/Mexico_City',
        ]);
    }
}
