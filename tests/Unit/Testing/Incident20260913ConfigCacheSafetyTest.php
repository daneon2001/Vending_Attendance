<?php

namespace Tests\Unit\Testing;

use App\Support\Testing\TestDatabasePolicy;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class Incident20260913ConfigCacheSafetyTest extends TestCase
{
    public function test_cached_local_database_aborts_before_next_bootstrap_step(): void
    {
        $root = sys_get_temp_dir().'/vending-safety-'.bin2hex(random_bytes(8));
        mkdir($root.'/bootstrap/cache', 0700, true);
        $cache = $root.'/bootstrap/cache/config.php';
        file_put_contents($cache, '<?php return '.var_export(['app' => ['env' => 'local'], 'database' => ['default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'database' => 'vending_attendance_dev']]]], true).';');
        try {
            $code = 'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
                .'putenv("APP_ENV=testing"); putenv("APP_CONFIG_CACHE=bootstrap/cache/config.php");'
                .'$_ENV["APP_CONFIG_CACHE"]=$_SERVER["APP_CONFIG_CACHE"]=getenv("APP_CONFIG_CACHE");'
                .'$app=new Illuminate\\Foundation\\Application('.var_export($root, true).');'
                .'App\\Support\\Testing\\TestDatabaseGuard::install($app);'
                .'try {$app->bootstrapWith([Illuminate\\Foundation\\Bootstrap\\LoadConfiguration::class]); echo "MUTATION_REACHED:".$app->getCachedConfigPath().":".getenv("APP_ENV").":".$app["config"]->get("app.env"); exit(9);} catch (LogicException $e) {echo $e->getMessage(); exit(23);}';
            $process = new Process([PHP_BINARY, '-r', $code]);
            $process->run();
            $this->assertSame(23, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            $this->assertStringContainsString('TEST_DATABASE_SAFETY_BLOCKED', $process->getOutput());
            $this->assertStringContainsString('database=vending_attendance_dev', $process->getOutput());
            $this->assertStringNotContainsString('MUTATION_REACHED', $process->getOutput());
        } finally {
            unlink($cache);
            rmdir($root.'/bootstrap/cache');
            rmdir($root.'/bootstrap');
            rmdir($root);
        }
    }

    public function test_policy_denies_protected_ambiguous_and_overridden_targets(): void
    {
        foreach (['vending_attendance_dev', 'vending_attendance', 'ASISTENCIAS_FORTIA', 'fortia', 'prod_testing_backup', 'vending_attendance_testing', 'vending_attendance_test_deadbeef', 'vending_attendance_test_0123456789abcdef_extra'] as $name) {
            try {
                TestDatabasePolicy::assertSafe('testing', ['driver' => 'mysql', 'database' => $name, 'host' => '127.0.0.1']);
                $this->fail('Unsafe target accepted');
            } catch (LogicException $e) {
                $this->assertStringContainsString('TEST_DATABASE_SAFETY_BLOCKED', $e->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        TestDatabasePolicy::assertSafe('testing', ['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'mysql://secret:secret@host/dev']);
    }

    public function test_safe_memory_and_unique_local_mysql_are_allowed(): void
    {
        TestDatabasePolicy::assertSafe('testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        TestDatabasePolicy::assertSafe('testing', ['driver' => 'mysql', 'database' => 'vending_attendance_test_0123456789abcdef', 'host' => '127.0.0.1']);
        $this->addToAssertionCount(2);
    }

    public function test_preboot_rejects_cache_without_loading_it(): void
    {
        $cache = tempnam(sys_get_temp_dir(), 'safety-cache-');
        file_put_contents($cache, '<?php throw new Exception("CACHE_EXECUTED");');
        try {
            $code = 'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).'; putenv("APP_CONFIG_CACHE=".'.var_export($cache, true).'); try {App\\Support\\Testing\\TestEnvironment::prepare('.var_export(dirname(__DIR__, 3), true).');exit(9);} catch (LogicException $e) {echo $e->getMessage();exit(23);}';
            $process = new Process([PHP_BINARY, '-r', $code]);
            $process->run();
            $this->assertSame(23, $process->getExitCode());
            $this->assertStringContainsString('TEST_DATABASE_SAFETY_BLOCKED', $process->getOutput());
            $this->assertStringNotContainsString('CACHE_EXECUTED', $process->getOutput().$process->getErrorOutput());
        } finally {
            unlink($cache);
        }
    }

    public function test_two_workers_have_independent_memory_databases(): void
    {
        $code = 'require '.var_export(dirname(__DIR__, 3).'/tests/bootstrap.php', true).';'
            .'$pdo=new PDO("sqlite::memory:"); $pdo->exec("CREATE TABLE sentinel(id INTEGER); INSERT INTO sentinel VALUES(1)"); echo $pdo->query("SELECT COUNT(*) FROM sentinel")->fetchColumn();';
        $workers = [new Process([PHP_BINARY, '-r', $code]), new Process([PHP_BINARY, '-r', $code])];
        try {
            foreach ($workers as $worker) {
                $worker->start();
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $this->assertSame('1', $worker->getOutput());
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_legacy_local_mysql_harnesses_abort_before_bootstrap(): void
    {
        foreach (['field_identity_mysql.php', 'field_support_mysql.php', 'support_mysql_concurrency.php', 'field_contributions_mysql.php'] as $script) {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Support/'.$script]);
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('TEST_DATABASE_SAFETY_BLOCKED', $process->getErrorOutput());
        }
    }
}
