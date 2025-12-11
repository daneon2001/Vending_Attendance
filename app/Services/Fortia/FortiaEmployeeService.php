<?php

namespace App\Services\Fortia;

class FortiaEmployeeService
{
    public function syncEmployees(array $filters = []): void
    {
        // TODO: consumir Fortia y hacer upsert en employees
    }

    public function syncEmployeeById(int $fortiaEmployeeId): void
    {
        // TODO: consumir Fortia para un empleado y actualizar registro local
    }
}
