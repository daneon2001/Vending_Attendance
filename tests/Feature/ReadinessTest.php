<?php

namespace Tests\Feature;

use App\Services\ReadinessProbe;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    public function test_private_probe_roundtrip_and_cleanup(): void
    {
        $dir = sys_get_temp_dir().'/vending-ready-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        config(['filesystems.disks.local.root' => $dir, 'filesystems.disks.support_private.root' => $dir]);
        try {
            $this->getJson('/ready')->assertOk()->assertExactJson(['status' => 'ready'])->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame([], glob($dir.'/.readiness-*'));
            config(['filesystems.disks.support_private.root' => $dir.'/missing']);
            $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'not_ready']);
        } finally {
            rmdir($dir);
        }
    }

    public function test_database_failure_is_generic_even_with_debug_enabled(): void
    {
        config(['database.default' => 'unconfigured_readiness_connection']);
        $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'not_ready']);
    }

    public function test_private_disks_are_never_public_links_or_servable(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(config('filesystems.disks.support_private.serve'));
        $this->assertSame([public_path('storage') => storage_path('app/public')], config('filesystems.links'));
        $this->get('/storage/private/beta-onboarding/testers.json')->assertNotFound();
    }
}
