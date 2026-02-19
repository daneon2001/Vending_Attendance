<?php

namespace Tests\Feature\Api;

class OnPremHeartbeatTest extends OnPremApiTestCase
{
    public function test_heartbeat_persists_status_and_returns_next_interval(): void
    {
        config(['onprem.next_heartbeat_seconds' => 15]);

        $fixture = $this->seedDeviceFixture('CH-XOCH-009', 'secret-heartbeat');

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'clock_id' => $fixture['clock_id'],
            'unit_id' => $fixture['unit_id'],
            'company_id' => $fixture['company_id'],
            'app_version' => '2.1.0',
            'api_ok' => true,
            'device_ok' => true,
            'pending_count' => 3,
            'last_event_at_utc' => '2026-02-18T19:00:00Z',
            'last_event_at_local' => '2026-02-18T13:00:00-06:00',
            'tz' => 'America/Mexico_City',
            'ip_local' => '192.168.1.100',
            'port' => 8080,
            'status_message' => 'RUNNING',
        ];

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/heartbeat',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('next_heartbeat_seconds', 15)
            ->assertJsonPath('device.device_serial', $fixture['device_serial'])
            ->assertJsonPath('device.last_status', 'RUNNING');

        $this->assertNotNull($response->json('server_time'));
        $this->assertNotNull($response->json('device.last_heartbeat_at'));

        $this->assertDatabaseHas('devices', [
            'device_serial' => $fixture['device_serial'],
            'last_status' => 'RUNNING',
        ]);
    }
}
