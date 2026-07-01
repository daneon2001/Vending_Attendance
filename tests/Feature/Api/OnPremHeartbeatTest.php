<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;

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

        $this->assertDatabaseHas('clocks', [
            'id' => $fixture['clock_id'],
            'monitoring_status' => 'online',
            'program_status' => 'online',
            'last_status_message' => 'RUNNING',
            'last_seen_ip' => '127.0.0.1',
        ]);
    }

    public function test_heartbeat_resolves_clock_by_serial_when_device_clock_mapping_is_missing(): void
    {
        $fixture = $this->seedDeviceFixture('749', 'secret-serial-only');

        \Illuminate\Support\Facades\DB::table('devices')
            ->where('device_serial', $fixture['device_serial'])
            ->update([
                'clock_id' => null,
                'unit_id' => null,
                'company_id' => null,
            ]);

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/heartbeat',
            [
                'device_serial' => $fixture['device_serial'],
                'status_message' => 'SERIAL_OK',
                'device_ok' => true,
                'api_ok' => true,
            ],
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('devices', [
            'device_serial' => $fixture['device_serial'],
            'clock_id' => $fixture['clock_id'],
            'unit_id' => $fixture['unit_id'],
            'company_id' => $fixture['company_id'],
        ]);

        $this->assertDatabaseHas('clocks', [
            'id' => $fixture['clock_id'],
            'monitoring_status' => 'online',
            'program_status' => 'online',
            'last_status_message' => 'SERIAL_OK',
        ]);
    }

    public function test_heartbeat_audit_is_throttled_between_status_changes(): void
    {
        config(['audit.cleanup.heartbeat_log_interval_minutes' => 30]);

        $fixture = $this->seedDeviceFixture('HB-171', 'secret-heartbeat-audit');

        $basePayload = [
            'device_serial' => $fixture['device_serial'],
            'clock_id' => $fixture['clock_id'],
            'unit_id' => $fixture['unit_id'],
            'company_id' => $fixture['company_id'],
            'api_ok' => true,
            'device_ok' => true,
            'status_message' => 'RUNNING',
        ];

        $this->signedJsonRequest(
            'POST',
            '/api/onprem/heartbeat',
            $basePayload,
            $fixture['device_serial'],
            $fixture['secret'],
        )->assertOk();

        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'onprem.heartbeat.status_changed',
        ]);

        $this->signedJsonRequest(
            'POST',
            '/api/onprem/heartbeat',
            $basePayload,
            $fixture['device_serial'],
            $fixture['secret'],
        )->assertOk();

        $this->assertSame(1, DB::table('audit_logs')->count());

        $this->signedJsonRequest(
            'POST',
            '/api/onprem/heartbeat',
            array_merge($basePayload, [
                'device_ok' => false,
                'status_message' => 'DEVICE_ERROR',
            ]),
            $fixture['device_serial'],
            $fixture['secret'],
        )->assertOk();

        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'onprem.heartbeat.status_changed',
            'description' => 'Heartbeat status changed',
        ]);

        $this->travel(31)->minutes();

        $this->signedJsonRequest(
            'POST',
            '/api/onprem/heartbeat',
            array_merge($basePayload, [
                'device_ok' => false,
                'status_message' => 'DEVICE_ERROR',
            ]),
            $fixture['device_serial'],
            $fixture['secret'],
        )->assertOk();

        $this->assertSame(3, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'onprem.heartbeat.sampled',
            'description' => 'Heartbeat sampled',
        ]);
    }
}
