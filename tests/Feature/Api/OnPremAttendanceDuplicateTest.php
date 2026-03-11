<?php

namespace Tests\Feature\Api;

class OnPremAttendanceDuplicateTest extends OnPremApiTestCase
{
    public function test_event_returns_duplicate_status_on_retry(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-002', 'secret-dup');
        $this->seedCollaborator(88002, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '22222222-2222-2222-2222-222222222222',
                    'collaborator_id' => 88002,
                    'punched_at_local' => '2026-02-18T09:10:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T15:10:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                    'quality' => 80,
                    'meta' => ['scanner' => 'S1'],
                ],
            ],
        ];

        $first = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );
        $first->assertOk()->assertJsonPath('received.0.status', 'STORED');
        $firstRemote = $first->json('received.0.remote_id');

        $second = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $second->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('received.0.stored', true)
            ->assertJsonPath('received.0.status', 'DUPLICATE')
            ->assertJsonPath('received.0.remote_id', $firstRemote)
            ->assertJsonPath('received.0.reason', null);

        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('attendances_raw')
                ->where('device_serial', $fixture['device_serial'])
                ->where('local_event_id', '22222222-2222-2222-2222-222222222222')
                ->count()
        );
    }
}
