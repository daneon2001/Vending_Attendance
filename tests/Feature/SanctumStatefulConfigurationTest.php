<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Container\Container;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Symfony\Component\Process\Process;

class SanctumStatefulConfigurationTest extends TestCase
{
    public static function environments(): iterable
    {
        foreach (['local', 'testing', 'beta', 'production'] as $environment) {
            yield $environment => [$environment];
        }
    }

    #[DataProvider('environments')]
    public function test_unsafe_configuration_blocks_http_in_every_environment(string $environment): void
    {
        $this->configureEnvironment($environment);
        Route::any('/synthetic-stateful-policy', fn () => response()->json(['reached' => true]));
        foreach (['*', '*.example.test', 'api.*.example.test', 'example.*',
            'localhost:8000,*', 'example.test,*.evil.test', 'https://example.test'] as $value) {
            $domains = explode(',', $value);
            config(['sanctum.stateful' => $domains]);
            $this->getJson('https://example.test/synthetic-stateful-policy')
                ->assertStatus(503)->assertExactJson(['message' => 'Invalid authentication configuration.'])
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame($domains, config('sanctum.stateful'));
        }
        $this->options('https://example.test/synthetic-stateful-policy')->assertStatus(503);
    }

    #[DataProvider('environments')]
    public function test_explicit_configuration_preserves_sanctum_matching(string $environment): void
    {
        $this->configureEnvironment($environment);
        Route::get('/synthetic-stateful-policy', fn () => response()->json(['reached' => true]));
        foreach (['example.test', 'example.test:8443', 'localhost', 'localhost:8000', '127.0.0.1:8000'] as $host) {
            config(['sanctum.stateful' => [' '.$host.' ', $host]]);
            $this->getJson('https://example.test/synthetic-stateful-policy')->assertOk()->assertExactJson(['reached' => true]);
            $request = Request::create('https://example.test/api', server: ['HTTP_ORIGIN' => 'https://'.$host]);
            $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
            $request->headers->set('Origin', 'https://unlisted.example.test');
            $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
        }
        config(['sanctum.stateful' => []]);
        $this->getJson('https://example.test/synthetic-stateful-policy')->assertOk();
    }

    public function test_sanctum_itself_interprets_wildcards_as_patterns(): void
    {
        foreach (['*' => 'arbitrary.test', '*.example.test' => 'a.example.test',
            'api.*.example.test' => 'api.a.example.test', 'example.*' => 'example.test',
            'localhost:8000,*' => 'arbitrary.test', 'example.test,*.evil.test' => 'a.evil.test'] as $list => $host) {
            config(['sanctum.stateful' => explode(',', $list)]);
            $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend(
                Request::create('https://example.test/api', server: ['HTTP_ORIGIN' => 'https://'.$host])
            ));
        }
    }

    #[DataProvider('environments')]
    public function test_cached_effective_values_are_checked_without_loading_operational_cache(string $environment): void
    {
        $this->configureEnvironment($environment);
        Route::get('/synthetic-cached-policy', fn () => response('ok'));
        $dir = sys_get_temp_dir().'/stateful-config-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        try {
            foreach ([[['*'], 503], [['example.test:8443'], 200]] as [$domains, $status]) {
                file_put_contents($dir.'/config.php', '<?php return '.var_export([
                    'app' => ['env' => 'testing', 'timezone' => 'UTC'], 'sanctum' => ['stateful' => $domains],
                ], true).';');
                // Only Laravel's configuration loader: no providers, DB binding or .env.
                // The repository's guard against booting tests from cached config stays intact.
                $fixture = new class($dir) extends Application
                {
                    public function getCachedConfigPath() { return $this->basePath('config.php'); }
                };
                try {
                    (new LoadConfiguration)->bootstrap($fixture);
                    $this->assertTrue($fixture->configurationIsCached());
                    $effective = $fixture['config']->get('sanctum.stateful');
                } finally {
                    Container::setInstance($this->app);
                }
                config(['sanctum.stateful' => $effective]);
                $this->getJson('https://example.test/synthetic-cached-policy')->assertStatus($status);
            }
        } finally {
            unlink($dir.'/config.php');
            rmdir($dir);
        }
    }

    public function test_invalid_configuration_does_not_block_cli_diagnostics(): void
    {
        $project = dirname(__DIR__, 2);
        $code = 'require '.var_export($project.'/tests/bootstrap.php', true).';'
            .'$app=require '.var_export($project.'/bootstrap/app.php', true).';'
            . <<<'PHP'
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            if (config('sanctum.stateful') !== ['*']) { throw new RuntimeException('Missing synthetic configuration'); }
            $results = [];
            foreach (['about' => ['--only' => 'environment', '--json' => true], 'route:list' => ['--json' => true]] as $command => $options) {
                $output = new Symfony\Component\Console\Output\BufferedOutput;
                $results[$command] = $kernel->handle(new Symfony\Component\Console\Input\ArrayInput(['command' => $command] + $options), $output);
            }
            echo json_encode($results);
            PHP;
        $worker = new Process([PHP_BINARY, '-r', $code], $project, ['SANCTUM_STATEFUL_DOMAINS' => '*']);
        $worker->run();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        $this->assertSame(['about' => 0, 'route:list' => 0], json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_http_first_boot_blocks_before_routing(): void
    {
        $project = dirname(__DIR__, 2);
        $code = 'require '.var_export($project.'/tests/bootstrap.php', true).';'
            .'$app=require '.var_export($project.'/bootstrap/app.php', true).';'
            .'$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class);'
            .'$response=$kernel->handle(Illuminate\\Http\\Request::create("https://example.test/not-a-route"));'
            .'echo json_encode([$response->getStatusCode(), $response->getContent()]);';
        $worker = new Process([PHP_BINARY, '-r', $code], $project, ['SANCTUM_STATEFUL_DOMAINS' => '*']);
        $worker->run();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        $this->assertSame([503, '{"message":"Invalid authentication configuration."}'],
            json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function configureEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        config(['app.debug' => true, 'app.url' => 'https://example.test']);
        if ($environment === 'beta') {
            config(['app.debug' => false, 'internal_beta.enabled' => true, 'internal_beta.trusted_proxies' => []]);
        }
    }
}
