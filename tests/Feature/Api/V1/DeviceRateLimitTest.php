<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\RateLimiter;

class DeviceRateLimitTest extends VendingDeviceApiTestCase
{
    public function test_provisioning_is_restrictively_throttled(): void
    {
        config(['vending.device.rate_limits.provision_per_minute' => 2]);
        RateLimiter::clear('vending-device-provision:127.0.0.1');
        $payload = ['provisioning_token' => str_repeat('a', 64), 'device_serial' => 'RATE'];

        $this->postJson('/api/v1/device/provision', $payload)->assertUnauthorized();
        $this->postJson('/api/v1/device/provision', $payload)->assertUnauthorized();
        $this->postJson('/api/v1/device/provision', $payload)->assertTooManyRequests();
    }

    public function test_heartbeat_allows_operational_frequency_before_throttling(): void
    {
        config(['vending.device.rate_limits.heartbeat_per_minute' => 3]);
        $provisioned = $this->provisionedDevice($this->machine(), 'RATE-HEARTBEAT');
        $payload = ['device_time' => now()->toIso8601String()];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->signedDeviceRequest('POST', '/api/v1/device/heartbeat', $payload, $provisioned['device'], $provisioned['credential'])->assertOk();
        }

        $this->signedDeviceRequest('POST', '/api/v1/device/heartbeat', $payload, $provisioned['device'], $provisioned['credential'])->assertTooManyRequests();
    }
}
