<?php

namespace Tests\Feature;

use App\Http\Middleware\BetaHttpBoundary;
use App\Services\FieldIdentity\LocalBetaTesterRegistry;
use Illuminate\Http\Request;
use Tests\TestCase;

class BetaBoundaryTest extends TestCase
{
    public function test_proxy_headers_only_work_from_explicit_trusted_proxy(): void
    {
        $this->app->detectEnvironment(fn () => 'beta');
        config(['app.debug' => false, 'app.url' => 'https://beta.example.test', 'internal_beta.enabled' => true,
            'internal_beta.trusted_proxies' => ['192.0.2.10']]);
        $boundary = new BetaHttpBoundary;
        $next = fn () => response('ok');
        try {
            $request = Request::create('http://beta.example.test/ready', server: ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => 'https']);
            $this->assertSame(200, $boundary->handle($request, $next)->getStatusCode());
            $request->server->set('REMOTE_ADDR', '192.0.2.11');
            $this->assertSame(403, $boundary->handle($request, $next)->getStatusCode());
            $this->assertSame(400, $boundary->handle(Request::create('https://wrong.example.test/ready'), $next)->getStatusCode());
            config(['internal_beta.trusted_proxies' => ['*']]);
            $this->assertSame(503, $boundary->handle($request, $next)->getStatusCode());
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }

    public function test_private_server_registry_denies_bad_files_and_production(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'beta-registry-test-');
        chmod($path, 0600);
        $this->app->detectEnvironment(fn () => 'beta');
        config(['app.debug' => false, 'internal_beta.enabled' => true,
            'internal_beta.testers_enabled' => true, 'internal_beta.registry_path' => $path]);
        $registry = new LocalBetaTesterRegistry;
        $entry = ['user_id' => 1, 'employee_id' => 1, 'employee_number' => 'SYNTHETIC', 'employee_source' => 'DEMO',
            'phone_e164' => '+525500000001', 'enabled' => true, 'approval_reference' => 'TEST',
            'created_at' => '2026-09-14T00:00:00Z', 'updated_at' => '2026-09-14T00:00:00Z', 'expires_at' => '2026-09-28T00:00:00Z'];
        try {
            file_put_contents($path, json_encode(['version' => 1, 'testers' => [$entry]]));
            $this->assertCount(1, $registry->entries());
            $this->app->detectEnvironment(fn () => 'production');
            $this->assertSame([], $registry->entries());
            $this->app->detectEnvironment(fn () => 'beta');
            file_put_contents($path, '{invalid');
            $this->assertSame([], $registry->entries());
            file_put_contents($path, str_repeat('x', 32769));
            clearstatcache(true, $path);
            $this->assertSame([], $registry->entries());
        } finally {
            unlink($path);
        }
    }
}
