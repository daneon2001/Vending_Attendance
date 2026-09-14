<?php

namespace App\Support\Testing;

final class TestEnvironment
{
    public static function prepare(string $root): void
    {
        if (defined('TEST_DATABASE_SAFETY_ACTIVE')) {
            return;
        }
        $cache = getenv('APP_CONFIG_CACHE');
        foreach ([$root.'/bootstrap/cache/config.php', $cache ? (str_starts_with($cache, $root) || preg_match('/^[A-Za-z]:|^\//', $cache) ? $cache : $root.'/'.$cache) : null] as $path) {
            if ($path && is_file($path)) {
                throw new \LogicException('TEST_DATABASE_SAFETY_BLOCKED environment=testing driver=UNKNOWN database=UNKNOWN reason=config cache exists; do not load cached test configuration');
            }
        }
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        $database = getenv('DB_DATABASE') ?: ':memory:';
        TestDatabasePolicy::assertSafe('testing', ['driver' => $driver, 'database' => $database, 'host' => getenv('DB_HOST') ?: '127.0.0.1', 'url' => getenv('DB_URL') ?: null, 'unix_socket' => getenv('DB_SOCKET') ?: null]);
        define('TEST_DATABASE_SAFETY_ACTIVE', true);
        foreach (['APP_ENV' => 'testing', 'APP_URL' => 'http://localhost', 'APP_CONFIG_CACHE' => 'storage/framework/cache/testing-config-'.getmypid().'.php', 'DB_CONNECTION' => $driver, 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        if (is_file($root.'/'.getenv('APP_CONFIG_CACHE'))) {
            throw new \LogicException('TEST_DATABASE_SAFETY_BLOCKED reason=isolated cache unexpectedly exists');
        }
        fwrite(STDERR, 'TEST ENVIRONMENT: testing'.PHP_EOL.'DATABASE DRIVER: '.$driver.PHP_EOL.'DATABASE SAFETY: PASS'.PHP_EOL);
    }
}
