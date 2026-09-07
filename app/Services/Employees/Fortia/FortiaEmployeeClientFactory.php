<?php

namespace App\Services\Employees\Fortia;

use App\Contracts\FortiaEmployeeClient;
use RuntimeException;

class FortiaEmployeeClientFactory
{
    public function make(): FortiaEmployeeClient
    {
        return match (strtolower((string) config('fortia.sync_driver'))) {
            'mock', 'fortia', 'database' => app(DatabaseFortiaEmployeeClient::class),
            'http', 'api' => app(HttpFortiaEmployeeClient::class),
            default => throw new RuntimeException('FORTIA_DRIVER_UNSUPPORTED'),
        };
    }

    public function describe(): array
    {
        $driver = strtolower((string) config('fortia.sync_driver'));

        return [
            'driver' => $driver,
            'source' => $driver === 'mock' ? 'DEMO' : 'FORTIA',
            'real_api_ready' => in_array($driver, ['http', 'api'], true) && config('employees.fortia.http_contract_approved') === true
                && filled(config('employees.fortia.employees_path')) && filled(config('employees.fortia.api_token')),
            'write_enabled' => config('employees.fortia.allow_write') === true,
            'reconciliation' => 'EXPLICIT_STATUS_ONLY',
        ];
    }
}
