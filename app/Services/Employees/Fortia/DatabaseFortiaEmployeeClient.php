<?php

namespace App\Services\Employees\Fortia;

use App\Contracts\FortiaEmployeeClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseFortiaEmployeeClient implements FortiaEmployeeClient
{
    public function fetch(array $filters = []): array
    {
        $connection = config('fortia.sync_driver') === 'mock' ? 'fortia_mock' : (string) config('fortia.sync_connection');
        $table = (string) config('fortia.sync_table');
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new RuntimeException('FORTIA_TABLE_INVALID');
        }
        $maximum = max(1, (int) config('employees.fortia.max_records', 5000));
        $rows = DB::connection($connection)->table($table)
            ->select(['id', 'employee_id', 'name', 'last_name', 'second_last_name', 'status', 'updated_at'])
            ->when(isset($filters['company_id']), fn ($query) => $query->where('company_id', $filters['company_id']))
            ->when(isset($filters['fortia_employee_id']), fn ($query) => $query->where('employee_id', $filters['fortia_employee_id']))
            ->orderBy('id')->limit($maximum + 1)->get();
        if ($rows->count() > $maximum) {
            throw new RuntimeException('FORTIA_RECORD_LIMIT');
        }

        return $rows->map(fn ($row) => [
            'employee_number' => (string) $row->employee_id,
            'source_external_id' => (string) $row->id,
            'full_name' => implode(' ', array_filter([$row->name, $row->last_name, $row->second_last_name], fn ($part) => $part !== null && $part !== '')),
            'status' => $row->status,
            'source_updated_at' => $row->updated_at,
        ])->all();
    }
}
