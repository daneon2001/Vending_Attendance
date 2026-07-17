<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'hire_date')) {
                $table->date('hire_date')->nullable()->after('status');
            }

            if (! Schema::hasColumn('employees', 'termination_date')) {
                $table->date('termination_date')->nullable()->after('hire_date');
            }
        });

        $this->backfillFromImportMetadata();
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('employees', 'hire_date')) {
                $columns[] = 'hire_date';
            }

            if (Schema::hasColumn('employees', 'termination_date')) {
                $columns[] = 'termination_date';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function backfillFromImportMetadata(): void
    {
        if (! Schema::hasTable('employee_import_metadata')) {
            return;
        }

        DB::table('employee_import_metadata')
            ->select(['id', 'employee_id', 'payload'])
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    if (! is_array($payload)) {
                        continue;
                    }

                    $updates = array_filter([
                        'hire_date' => $this->normalizeDate($payload['fecha_ing'] ?? null),
                        'termination_date' => $this->normalizeDate($payload['fecha_baja'] ?? null),
                    ], fn (?string $value): bool => $value !== null);

                    if ($updates === []) {
                        continue;
                    }

                    foreach ($updates as $column => $value) {
                        DB::table('employees')
                            ->where('id', $row->employee_id)
                            ->whereNull($column)
                            ->update([$column => $value]);
                    }
                }
            }, 'id');
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
