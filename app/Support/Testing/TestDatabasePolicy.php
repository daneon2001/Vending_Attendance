<?php

namespace App\Support\Testing;

use LogicException;

final class TestDatabasePolicy
{
    public static function assertSafe(string $environment, array $config): void
    {
        $driver = (string) ($config['driver'] ?? 'UNKNOWN');
        $database = (string) ($config['database'] ?? 'UNKNOWN');
        $reason = null;
        if (in_array(strtolower($database), ['vending_attendance_dev', 'vending_attendance', 'vending_attendance_pilot', 'asistencias_fortia', 'fortia', 'fortia_mock', 'fortia_fake'], true)) {
            $reason = 'protected database';
        } elseif ($environment !== 'testing') {
            $reason = 'effective environment must be testing';
        } elseif (! empty($config['url']) || isset($config['read']) || isset($config['write'])) {
            $reason = 'URL and read/write overrides are not allowed';
        } elseif ($driver === 'sqlite' && $database === ':memory:') {
            return;
        } elseif ($driver === 'mysql' && preg_match('/\Avending_attendance_test_[a-f0-9]{16}\z/', $database) === 1
            && in_array($config['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            && empty($config['unix_socket'])) {
            return;
        } else {
            $reason = 'only SQLite memory or loopback disposable MySQL names are allowed';
        }
        // Never echo URLs, credentials, arbitrary DSNs or control characters.
        $safe = static fn ($v) => preg_match('/\A[a-zA-Z0-9_.:-]{1,100}\z/', $v) ? $v : '[redacted]';
        throw new LogicException('TEST_DATABASE_SAFETY_BLOCKED environment='.$safe($environment).' driver='.$safe($driver).' database='.$safe($database).' reason='.$reason);
    }
}
