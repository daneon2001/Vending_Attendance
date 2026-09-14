<?php

namespace App\Support\Testing;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;

final class TestDatabaseGuard
{
    public static function install(Application $app): void
    {
        $app->afterBootstrapping(LoadConfiguration::class, static function ($app): void {
            if (defined('TEST_DATABASE_SAFETY_ACTIVE') || class_exists(\PHPUnit\Framework\TestCase::class, false) || $app['config']->get('app.env') === 'testing' || getenv('APP_ENV') === 'testing') {
                self::assertDefault($app);
                // External fixtures always live in independent in-memory SQLite databases.
                foreach (['fortia', 'fortia_mock'] as $name) {
                    $app['config']->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
                }
                if ($app->configurationIsCached()) {
                    throw new \LogicException('TEST_DATABASE_SAFETY_BLOCKED reason=effective configuration was cached');
                }
            }
        });
        $app->afterBootstrapping(RegisterProviders::class, static function ($app): void {
            if (defined('TEST_DATABASE_SAFETY_ACTIVE') || class_exists(\PHPUnit\Framework\TestCase::class, false) || $app['config']->get('app.env') === 'testing' || getenv('APP_ENV') === 'testing') {
                $app->singleton('db.factory', fn ($app) => new SafeConnectionFactory($app));
                $app->singleton('db', fn ($app) => new SafeDatabaseManager($app, $app['db.factory']));
            }
            $app->extend(\Illuminate\Database\Console\Migrations\FreshCommand::class, fn ($command, $app) => new SafeFreshCommand($app['migrator']));
            $app->extend(\Illuminate\Database\Console\Migrations\RefreshCommand::class, fn ($command, $app) => new SafeRefreshCommand);
            $app->extend(\Illuminate\Database\Console\Migrations\ResetCommand::class, fn ($command, $app) => new SafeResetCommand($app['migrator']));
            $app->extend(\Illuminate\Database\Console\Migrations\RollbackCommand::class, fn ($command, $app) => new SafeRollbackCommand($app['migrator']));
            $app->extend(\Illuminate\Database\Console\WipeCommand::class, fn ($command, $app) => new SafeWipeCommand);
            $app['events']->listen(CommandStarting::class, static function (CommandStarting $event) use ($app): void {
                if (! in_array($event->command, ['migrate:fresh', 'migrate:refresh', 'db:wipe', 'migrate:reset', 'migrate:rollback'], true)) {
                    return;
                }
                $name = $event->input->hasParameterOption('--database') ? $event->input->getParameterOption('--database') : null;
                // Artisan::call uses ArrayInput, whose raw option is also available here.
                if (! $name && $event->input->hasOption('database')) {
                    $name = $event->input->getOption('database');
                }
                $name = $name ?: $app['config']->get('database.default');
                TestDatabasePolicy::assertSafe((string) $app['config']->get('app.env'), (array) $app['config']->get('database.connections.'.$name, []));
            });
        });
    }

    public static function assertDefault(Application $app): void
    {
        $name = $app['config']->get('database.default');
        TestDatabasePolicy::assertSafe((string) $app['config']->get('app.env'), (array) $app['config']->get('database.connections.'.$name, []));
    }
}
