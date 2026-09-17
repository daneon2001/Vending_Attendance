<?php

namespace Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FrameworkCacheIsolationTest extends TestCase
{
    private string $root;

    private const SENTINEL = '<?php throw new RuntimeException("CACHE_EXECUTED");';

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/vending-cache-isolation-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/bootstrap/cache', 0700, true);
        mkdir($this->root.'/storage/framework/cache', 0700, true);
        foreach (['routes-v7.php', 'events.php'] as $name) {
            file_put_contents($this->root.'/bootstrap/cache/'.$name, self::SENTINEL);
        }
    }

    protected function tearDown(): void
    {
        // Only the exact files/directories owned by this fixture; no recursive removal.
        foreach (['routes-v7.php', 'events.php'] as $name) {
            unlink($this->root.'/bootstrap/cache/'.$name);
        }
        foreach (['bootstrap/cache', 'bootstrap', 'storage/framework/cache', 'storage/framework', 'storage'] as $dir) {
            rmdir($this->root.'/'.$dir);
        }
        rmdir($this->root);
    }

    public function test_workers_isolate_both_caches_and_ignore_existing_local_caches(): void
    {
        $workers = [$this->worker(), $this->worker()];
        $results = [];
        try {
            foreach ($workers as $worker) {
                $worker->start();
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $data = $this->workerResult($worker);
                foreach (['APP_ROUTES_CACHE' => 'routes', 'APP_EVENTS_CACHE' => 'events'] as $key => $kind) {
                    $relative = 'storage/framework/cache/testing-'.$kind.'-'.$data['pid'].'.php';
                    $this->assertSame([$relative, $relative, $relative], $data['variables'][$key]);
                    $this->assertSame($this->root.'/'.$relative, str_replace('\\', '/', $data['paths'][$kind]));
                    $this->assertFalse($data['cached'][$kind]);
                }
                $results[] = $data;
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
        $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
        foreach (['routes', 'events'] as $kind) {
            $this->assertNotSame($results[0]['paths'][$kind], $results[1]['paths'][$kind]);
        }
        foreach (['routes-v7.php', 'events.php'] as $name) {
            $this->assertSame(self::SENTINEL, file_get_contents($this->root.'/bootstrap/cache/'.$name));
        }
    }

    public function test_routes_collision_is_rejected_without_touching_the_cache(): void
    {
        $this->assertCollision('routes');
    }

    public function test_events_collision_is_rejected_without_touching_the_cache(): void
    {
        $this->assertCollision('events');
    }

    private function assertCollision(string $kind): void
    {
        $worker = $this->worker($kind);
        $worker->run();
        $data = $this->workerResult($worker);
        $this->assertTrue($data['blocked']);
        $this->assertTrue($data['preserved']);
        $this->assertSame([], glob($this->root.'/storage/framework/cache/*'));
    }

    private function workerResult(Process $worker): array
    {
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
        $this->assertStringNotContainsString('CACHE_EXECUTED', $worker->getOutput().$worker->getErrorOutput());

        return json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function worker(?string $collision = null): Process
    {
        $project = dirname(__DIR__, 3);
        $source = $project.'/app/Support/Testing/TestEnvironment.php';
        $code = 'require '.var_export($project.'/vendor/autoload.php', true).';'
            .'require '.var_export($source, true).';'
            .'$root='.var_export($this->root, true).'; $collision='.var_export($collision, true).';'
            . <<<'PHP'
            $file = $collision === null ? null : $root.'/storage/framework/cache/testing-'.$collision.'-'.getmypid().'.php';
            $sentinel = '<?php throw new RuntimeException("CACHE_EXECUTED");';
            if ($file !== null) { file_put_contents($file, $sentinel); }
            try {
                $blocked = false;
                try {
                    App\Support\Testing\TestEnvironment::prepare($root);
                } catch (LogicException $error) {
                    if ($file === null || ! str_contains($error->getMessage(), 'isolated cache unexpectedly exists')) { throw $error; }
                    $blocked = true;
                }
                if ($file !== null) {
                    echo json_encode(['blocked' => $blocked, 'preserved' => is_file($file) && file_get_contents($file) === $sentinel]);
                } else {
                    $app = new Illuminate\Foundation\Application($root);
                    $app->instance('files', new Illuminate\Filesystem\Filesystem);
                    $variables = [];
                    foreach (['APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
                        $variables[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
                    }
                    echo json_encode(['pid' => getmypid(), 'variables' => $variables,
                        'paths' => ['routes' => $app->getCachedRoutesPath(), 'events' => $app->getCachedEventsPath()],
                        'cached' => ['routes' => $app->routesAreCached(), 'events' => $app->eventsAreCached()]]);
                }
            } finally {
                if ($file !== null && is_file($file)) { unlink($file); }
            }
            PHP;

        return new Process([PHP_BINARY, '-r', $code], $project, [
            'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => false,
            'APP_ROUTES_CACHE' => 'bootstrap/cache/routes-v7.php', 'APP_EVENTS_CACHE' => 'bootstrap/cache/events.php',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'DB_SOCKET' => '',
        ], null, 30);
    }
}
