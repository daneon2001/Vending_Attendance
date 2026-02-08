<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $rows = DB::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$indexName]);
            return count($rows) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function up(): void
    {
        if (!Schema::hasTable('attendance_logs')) {
            return;
        }

        // 1) Columna
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_logs', 'local_id')) {
                $table->string('local_id', 100)->nullable()->after('device_id');
            }
        });

        // 2) Índice unique (solo si no existe)
        if (!$this->indexExists('attendance_logs', 'attendance_logs_local_device_unique')) {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->unique(['local_id', 'device_id'], 'attendance_logs_local_device_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('attendance_logs')) {
            return;
        }

        // dropUnique puede fallar si no existe, por eso el try/catch
        Schema::table('attendance_logs', function (Blueprint $table) {
            try {
                $table->dropUnique('attendance_logs_local_device_unique');
            } catch (\Throwable $e) {
                // no-op
            }

            if (Schema::hasColumn('attendance_logs', 'local_id')) {
                $table->dropColumn('local_id');
            }
        });
    }
};
