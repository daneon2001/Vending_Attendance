<?php

namespace App\Support\Testing;

use Illuminate\Database\DatabaseManager;

final class SafeDatabaseManager extends DatabaseManager
{
    private string $bootEnvironment;

    public function __construct($app, $factory)
    {
        parent::__construct($app, $factory);
        $this->bootEnvironment = (string) $app['config']->get('app.env');
    }

    public function connection($name = null)
    {
        [$connection] = $this->parseConnectionName($name);
        TestDatabasePolicy::assertSafe($this->bootEnvironment, $this->configuration($connection));

        return parent::connection($name);
    }
}
