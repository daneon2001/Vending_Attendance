<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('employees')
            || ! Schema::hasColumn('employees', 'hire_date')
            || ! Schema::hasTable('employee_import_metadata')
        ) {
            return;
        }

        DB::table('employee_import_metadata')
            ->select(['id', 'employee_id', 'payload'])
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    $hireDate = is_array($payload)
                        ? $this->normalizeDate($payload['fecha_ing_grupo'] ?? null)
                        : null;

                    if ($hireDate === null) {
                        continue;
                    }

                    DB::table('employees')
                        ->where('id', $row->employee_id)
                        ->update(['hire_date' => $hireDate]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Data correction is intentionally irreversible.
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
};
