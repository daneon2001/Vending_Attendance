<?php

namespace App\Support;

final class LocalIntegrationMigrations
{
    public static function fortiaMock(): bool
    {
        return app()->environment(['local', 'testing'])
            && config('integrations.fortia_mock_migrations') === true;
    }
}
