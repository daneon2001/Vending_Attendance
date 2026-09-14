<?php

namespace App\Support\Testing;

use Illuminate\Database\Connectors\ConnectionFactory;

final class SafeConnectionFactory extends ConnectionFactory
{
    private string $bootEnvironment;

    public function __construct($container)
    {
        parent::__construct($container);
        $this->bootEnvironment = (string) $container['config']->get('app.env');
    }

    public function make(array $config, $name = null)
    {
        TestDatabasePolicy::assertSafe($this->bootEnvironment, $config);

        return parent::make($config, $name);
    }
}
