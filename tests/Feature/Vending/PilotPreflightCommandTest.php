<?php

namespace Tests\Feature\Vending;

use Tests\TestCase;

class PilotPreflightCommandTest extends TestCase
{
    public function test_preflight_passes_with_non_secret_pilot_configuration(): void
    {
        app()->detectEnvironment(fn (): string => 'pilot');
        config([
            'app.debug' => false,
            'app.url' => 'https://pilot-vending.example.test',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'vending_attendance_pilot',
            'queue.default' => 'database',
            'cache.default' => 'database',
            'vending.pilot.scheduler_supervised' => true,
            'vending.pilot.mobile_api_url' => 'https://pilot-vending.example.test',
            'session.secure' => true,
            'logging.channels.single.level' => 'warning',
        ]);

        $this->artisan('vending:pilot-preflight')->assertSuccessful();
    }

    public function test_preflight_rejects_debug_cleartext_and_development_database(): void
    {
        config([
            'app.debug' => true,
            'app.url' => 'http://127.0.0.1',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'vending_attendance_dev',
            'queue.default' => 'sync',
            'cache.default' => 'file',
            'vending.pilot.scheduler_supervised' => false,
            'vending.pilot.mobile_api_url' => 'http://192.168.1.2',
            'session.secure' => false,
            'logging.channels.single.level' => 'debug',
        ]);

        $this->artisan('vending:pilot-preflight')->assertFailed();
    }
}
