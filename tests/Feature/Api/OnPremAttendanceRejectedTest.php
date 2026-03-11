<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

class OnPremAttendanceRejectedTest extends OnPremApiTestCase
{
    public function test_rejects_event_with_invalid_unit(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-003', 'secret-rej-unit');
        $this->seedCollaborator(88003, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => 999999,
            'events' => [
                [
                    'local_event_id' => '33333333-3333-3333-3333-333333333333',
                    'collaborator_id' => 88003,
                    'punched_at_local' => '2026-02-18T10:00:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T16:00:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                    'quality' => 77,
                ],
            ],
        ];

        $response = $this->signedJsonRequest('POST', '/api/onprem/attendances', $payload, $fixture['device_serial'], $fixture['secret']);

        $response->assertOk()
            ->assertJsonPath('received.0.status', 'REJECTED')
            ->assertJsonPath('received.0.reason', 'INVALID_UNIT')
            ->assertJsonPath('received.0.stored', false)
            ->assertJsonPath('received.0.remote_id', null);
    }

    public function test_rejects_event_with_unknown_collaborator(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-004', 'secret-rej-collab');

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '44444444-4444-4444-4444-444444444444',
                    'collaborator_id' => 123456,
                    'punched_at_local' => '2026-02-18T10:10:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T16:10:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                    'quality' => 79,
                ],
            ],
        ];

        $response = $this->signedJsonRequest('POST', '/api/onprem/attendances', $payload, $fixture['device_serial'], $fixture['secret']);

        $response->assertOk()
            ->assertJsonPath('received.0.status', 'REJECTED')
            ->assertJsonPath('received.0.reason', 'UNKNOWN_COLLABORATOR');
    }

    public function test_rejects_event_with_invalid_timestamp(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-005', 'secret-rej-ts');
        $this->seedCollaborator(88005, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '55555555-5555-5555-5555-555555555555',
                    'collaborator_id' => 88005,
                    'punched_at_local' => '2026-02-18T10:20:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T00:00:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                    'quality' => 70,
                ],
            ],
        ];

        $response = $this->signedJsonRequest('POST', '/api/onprem/attendances', $payload, $fixture['device_serial'], $fixture['secret']);

        $response->assertOk()
            ->assertJsonPath('received.0.status', 'REJECTED')
            ->assertJsonPath('received.0.reason', 'INVALID_TIMESTAMP');
    }

    public function test_returns_batch_too_large_when_limit_is_exceeded(): void
    {
        config(['onprem.max_batch_size' => 1]);

        $fixture = $this->seedDeviceFixture('CH-XOCH-006', 'secret-rej-batch');
        $this->seedCollaborator(88006, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '66666666-6666-6666-6666-666666666666',
                    'collaborator_id' => 88006,
                    'punched_at_local' => '2026-02-18T11:00:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T17:00:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                ],
                [
                    'local_event_id' => '77777777-7777-7777-7777-777777777777',
                    'collaborator_id' => 88006,
                    'punched_at_local' => '2026-02-18T11:01:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T17:01:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                ],
            ],
        ];

        $response = $this->signedJsonRequest('POST', '/api/onprem/attendances', $payload, $fixture['device_serial'], $fixture['secret']);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'BATCH_TOO_LARGE');
    }

    public function test_rejects_invalid_signature_with_code(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-007', 'secret-rej-sign');
        $this->seedCollaborator(88007, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '88888888-8888-8888-8888-888888888888',
                    'collaborator_id' => 88007,
                    'punched_at_local' => '2026-02-18T11:20:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T17:20:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                ],
            ],
        ];

        $response = $this->signedJsonRequest('POST', '/api/onprem/attendances', $payload, $fixture['device_serial'], 'wrong-secret');

        $response->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'INVALID_SIGNATURE');
    }

    public function test_rejects_nonce_replay_with_code(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-008', 'secret-rej-nonce');
        $this->seedCollaborator(88008, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '99999999-9999-9999-9999-999999999999',
                    'collaborator_id' => 88008,
                    'punched_at_local' => '2026-02-18T12:00:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T18:00:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                ],
            ],
        ];

        $timestamp = now()->timestamp;
        $nonce = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

        $first = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
            $timestamp,
            $nonce
        );
        $first->assertOk();

        $second = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
            $timestamp,
            $nonce
        );

        $second->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'NONCE_REPLAY');
    }

    public function test_rejects_inactive_device_with_code(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-010', 'secret-inactive');
        $this->seedCollaborator(88010, $fixture['company_id'], $fixture['unit_id']);

        DB::table('devices')
            ->where('device_serial', $fixture['device_serial'])
            ->update(['is_active' => 0]);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '10101010-1010-1010-1010-101010101010',
                    'collaborator_id' => 88010,
                    'punched_at_local' => '2026-02-18T12:05:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T18:05:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                ],
            ],
        ];

        $response = $this->signedJsonRequest('POST', '/api/onprem/attendances', $payload, $fixture['device_serial'], $fixture['secret']);

        $response->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'DEVICE_NOT_ACTIVE');
    }

    public function test_rejects_out_of_window_hmac_timestamp(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-011', 'secret-old-ts');
        $this->seedCollaborator(88011, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '11111110-1110-1110-1110-111011101110',
                    'collaborator_id' => 88011,
                    'punched_at_local' => '2026-02-18T12:15:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T18:15:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                ],
            ],
        ];

        $oldTimestamp = now()->subMinutes(20)->timestamp;
        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
            $oldTimestamp
        );

        $response->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'INVALID_TIMESTAMP');
    }
}
