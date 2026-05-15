<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexCache = [];

    public function up(): void
    {
        $this->addIndexIfMissing('attendance_logs', 'att_logs_emp_dev_date_idx', ['employee_id', 'device_id', 'log_date']);

        $this->addIndexIfMissing('employee_fingerprints', 'emp_fp_emp_status_del_idx', ['employee_id', 'status', 'deleted_at']);
        $this->addIndexIfMissing('employee_fingerprints', 'emp_fp_type_stat_del_upd_idx', ['enrolment_type', 'status', 'deleted_at', 'updated_at']);

        $this->addIndexIfMissing('employee_face_templates', 'emp_face_emp_active_idx', ['employee_id', 'is_active']);
        $this->addIndexIfMissing('employee_face_templates', 'emp_face_act_model_upd_idx', ['is_active', 'model_name', 'updated_at']);

        $this->addIndexIfMissing('employee_template_deletions', 'emp_tpl_del_vendor_tpl_idx', ['vendor', 'vendor_template_id']);
        $this->addIndexIfMissing('employee_template_deletions', 'emp_tpl_del_scope_del_idx', ['scope_location_id', 'deleted_at']);
        $this->addIndexIfMissing('employee_template_deletions', 'emp_tpl_del_emp_del_idx', ['employee_id', 'deleted_at']);

        $this->addIndexIfMissing('clocks', 'clocks_serial_number_idx', ['serial_number']);
        $this->addIndexIfMissing('clocks', 'clocks_location_status_idx', ['location_id', 'status']);
        $this->addIndexIfMissing('clocks', 'clocks_company_location_idx', ['company_id', 'location_id']);

        $this->addIndexIfMissing('locations', 'locations_company_status_idx', ['company_id', 'status']);
        $this->addIndexIfMissing('companies', 'companies_status_idx', ['status']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('companies', 'companies_status_idx');
        $this->dropIndexIfExists('locations', 'locations_company_status_idx');

        $this->dropIndexIfExists('clocks', 'clocks_company_location_idx');
        $this->dropIndexIfExists('clocks', 'clocks_location_status_idx');
        $this->dropIndexIfExists('clocks', 'clocks_serial_number_idx');

        $this->dropIndexIfExists('employee_template_deletions', 'emp_tpl_del_emp_del_idx');
        $this->dropIndexIfExists('employee_template_deletions', 'emp_tpl_del_scope_del_idx');
        $this->dropIndexIfExists('employee_template_deletions', 'emp_tpl_del_vendor_tpl_idx');

        $this->dropIndexIfExists('employee_face_templates', 'emp_face_act_model_upd_idx');
        $this->dropIndexIfExists('employee_face_templates', 'emp_face_emp_active_idx');

        $this->dropIndexIfExists('employee_fingerprints', 'emp_fp_type_stat_del_upd_idx');
        $this->dropIndexIfExists('employee_fingerprints', 'emp_fp_emp_status_del_idx');

        $this->dropIndexIfExists('attendance_logs', 'att_logs_emp_dev_date_idx');
    }

    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (! $this->tableExists($table) || ! $this->columnsExist($table, $columns)) {
            return;
        }

        if ($this->indexExists($table, $indexName) || $this->indexColumnsExist($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });

        unset($this->indexCache[$table]);
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->tableExists($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });

        unset($this->indexCache[$table]);
    }

    private function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    private function columnsExist(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->columnExists($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return array_key_exists($indexName, $this->indexesFor($table));
    }

    private function indexColumnsExist(string $table, array $columns): bool
    {
        $normalized = array_values($columns);

        foreach ($this->indexesFor($table) as $indexedColumns) {
            if ($indexedColumns === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function indexesFor(string $table): array
    {
        if (array_key_exists($table, $this->indexCache)) {
            return $this->indexCache[$table];
        }

        $indexes = [];

        try {
            $rows = DB::select("SHOW INDEX FROM `$table`");

            foreach ($rows as $row) {
                $name = (string) ($row->Key_name ?? '');
                $position = max(0, ((int) ($row->Seq_in_index ?? 1)) - 1);
                $column = (string) ($row->Column_name ?? '');

                if ($name === '' || $column === '') {
                    continue;
                }

                if (! array_key_exists($name, $indexes)) {
                    $indexes[$name] = [];
                }

                $indexes[$name][$position] = $column;
            }
        } catch (\Throwable $exception) {
            return $this->indexCache[$table] = [];
        }

        foreach ($indexes as $name => $columns) {
            ksort($columns);
            $indexes[$name] = array_values($columns);
        }

        return $this->indexCache[$table] = $indexes;
    }
};
