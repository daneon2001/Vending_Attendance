<?php

namespace Tests\Feature\Testing;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalIntegrationMigrationsTest extends TestCase
{
    public static function gateCases(): array
    {
        return [
            'local explicitly enabled' => ['local', true, true],
            'testing explicitly enabled' => ['testing', true, true],
            'missing flag' => ['testing', null, false],
            'false flag' => ['testing', false, false],
            'local disabled' => ['local', false, false],
            'string is not boolean opt-in' => ['testing', 'true', false],
            'beta cannot opt in' => ['beta', true, false],
            'production cannot opt in' => ['production', true, false],
        ];
    }

    private function configureGate(string $environment, mixed $flag): void
    {
        // Bootstrap and database guards have already validated SQLite :memory:.
        $this->app->instance('env', $environment);
        config(['app.env' => $environment, 'integrations' => []]);
        if ($flag !== null) {
            config(['integrations.fortia_mock_migrations' => $flag]);
        }
    }

    #[DataProvider('gateCases')]
    public function test_mock_table_creation_and_removal_respect_gate(
        string $environment, mixed $flag, bool $allowed
    ): void {
        $this->configureGate($environment, $flag);
        $connection = DB::connection('fortia_mock');
        $schema = $connection->getSchemaBuilder();
        $queries = 0;
        $observing = false;
        $connection->beforeExecuting(function () use (&$queries, &$observing): void {
            if ($observing) {
                $queries++;
            }
        });
        $migration = require database_path('migrations/2025_12_13_000000_create_fortia_employees_table.php');

        $observing = true;
        try {
            $migration->up();
        } finally {
            $observing = false;
        }
        $this->assertSame($allowed, $schema->hasTable('fortia_employees'));
        if (! $allowed) {
            $this->assertSame(0, $queries, 'Denied migration must not even inspect the mock schema.');
            $schema->create('fortia_employees', fn (Blueprint $table) => $table->integer('id'));
            $connection->table('fortia_employees')->insert(['id' => 1]);
        }

        $queries = 0;
        $observing = true;
        try {
            $migration->down();
        } finally {
            $observing = false;
        }
        $this->assertSame(! $allowed, $schema->hasTable('fortia_employees'));
        if (! $allowed) {
            $this->assertSame(0, $queries, 'Denied rollback must not touch the mock schema.');
            $this->assertSame(1, $connection->table('fortia_employees')->count());
        }
    }

    #[DataProvider('gateCases')]
    public function test_employee_column_is_independent_of_mock_gate(
        string $environment, mixed $flag, bool $allowed
    ): void {
        $this->configureGate($environment, $flag);
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('base_location_name')->nullable();
        });
        $connection = DB::connection('fortia_mock');
        $schema = $connection->getSchemaBuilder();
        $schema->create('fortia_employees', function (Blueprint $table): void {
            $table->id();
            $table->string('base_location_name')->nullable();
        });
        $queries = 0;
        $observing = false;
        $connection->beforeExecuting(function () use (&$queries, &$observing): void {
            if ($observing) {
                $queries++;
            }
        });
        $migration = require database_path('migrations/2026_03_17_130000_add_can_check_all_branches_to_employees_tables.php');

        $observing = true;
        try {
            $migration->up();
            $migration->up();
        } finally {
            $observing = false;
        }
        $this->assertTrue(Schema::hasColumn('employees', 'can_check_all_branches'));
        $this->assertSame($allowed, $schema->hasColumn('fortia_employees', 'can_check_all_branches'));
        if (! $allowed) {
            $this->assertSame(0, $queries);
            $schema->table('fortia_employees', fn (Blueprint $table) => $table->boolean('can_check_all_branches')->default(false));
        }

        $queries = 0;
        $observing = true;
        try {
            $migration->down();
            $migration->down();
        } finally {
            $observing = false;
        }
        $this->assertFalse(Schema::hasColumn('employees', 'can_check_all_branches'));
        $this->assertSame(! $allowed, $schema->hasColumn('fortia_employees', 'can_check_all_branches'));
        if (! $allowed) {
            $this->assertSame(0, $queries);
        }
    }
}
