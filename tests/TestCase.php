<?php

namespace Tests;

use App\Support\Testing\TestDatabaseGuard;
use App\Support\Testing\TestEnvironment;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private array $safeDatabaseConfiguration = [];

    public function createApplication()
    {
        TestEnvironment::prepare(dirname(__DIR__));
        $app = parent::createApplication();
        TestDatabaseGuard::assertDefault($app);
        $this->safeDatabaseConfiguration = $app['config']->get('database');

        return $app;
    }

    protected function tearDown(): void
    {
        // Restore only the previously validated test configuration before trait rollback.
        // Tests may simulate production config; cleanup must not resolve that target.
        if ($this->app && $this->safeDatabaseConfiguration !== []) {
            $this->app['config']->set('app.env', 'testing');
            $this->app['config']->set('database', $this->safeDatabaseConfiguration);
        }
        parent::tearDown();
    }
}
