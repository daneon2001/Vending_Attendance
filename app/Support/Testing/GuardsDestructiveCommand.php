<?php

namespace App\Support\Testing;

trait GuardsDestructiveCommand
{
    public function handle()
    {
        $name = $this->option('database') ?: $this->laravel['config']->get('database.default');
        TestDatabasePolicy::assertSafe((string) $this->laravel['config']->get('app.env'), (array) $this->laravel['config']->get('database.connections.'.$name, []));

        return parent::handle();
    }
}
