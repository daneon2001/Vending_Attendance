<?php

namespace Tests\Feature\Testing;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class DatabaseSafetyTest extends TestCase
{
    public function test_memory_migrations_are_allowed(): void
    {
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertSame(105, DB::table('migrations')->count());
        $this->assertSame(0, Artisan::call('db:wipe', ['--force' => true]));
        $path = ['--path' => 'tests/Fixtures/database-safety', '--force' => true];
        $this->assertSame(0, Artisan::call('migrate', $path));
        $this->assertSame(0, Artisan::call('migrate:refresh', $path));
    }

    public function test_destructive_commands_reject_unsafe_named_connection_before_resolution(): void
    {
        config(['database.connections.sentinel' => ['driver' => 'mysql', 'database' => 'vending_attendance_dev', 'host' => '127.0.0.1']]);
        // Tripwire replaces actual connector: even a broken guard cannot open MySQL.
        DB::extend('sentinel', fn () => throw new \RuntimeException('CONNECTION_REACHED'));
        foreach (['migrate:fresh', 'migrate:refresh', 'db:wipe'] as $command) {
            try {
                Artisan::call($command, ['--database' => 'sentinel', '--force' => true]);
                $this->fail('Command accepted unsafe target');
            } catch (LogicException $e) {
                $this->assertStringContainsString('TEST_DATABASE_SAFETY_BLOCKED', $e->getMessage());
            }
        }
    }

    public function test_secondary_connection_is_denied_before_pdo_creation(): void
    {
        config(['database.connections.sentinel' => ['driver' => 'mysql', 'database' => 'vending_attendance_dev', 'host' => 'invalid.invalid']]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('TEST_DATABASE_SAFETY_BLOCKED');
        DB::connection('sentinel');
    }
}
