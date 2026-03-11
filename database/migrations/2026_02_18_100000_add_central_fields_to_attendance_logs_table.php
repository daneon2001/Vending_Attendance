<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $rows = DB::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$indexName]);

            return count($rows) > 0;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendance_logs', 'source')) {
                $table->string('source', 20)->default('sync')->after('log_type');
            }

            if (! Schema::hasColumn('attendance_logs', 'attendance_status')) {
                $table->string('attendance_status', 20)->default('valida')->after('source');
            }

            if (! Schema::hasColumn('attendance_logs', 'adjustment_reason')) {
                $table->string('adjustment_reason', 500)->nullable()->after('attendance_status');
            }

            if (! Schema::hasColumn('attendance_logs', 'annulled_at')) {
                $table->dateTime('annulled_at')->nullable()->after('adjustment_reason');
            }

            if (! Schema::hasColumn('attendance_logs', 'annulled_by_user_id')) {
                $table->unsignedBigInteger('annulled_by_user_id')->nullable()->after('annulled_at');
            }
        });

        if (Schema::hasTable('users') && ! $this->indexExists('attendance_logs', 'attendance_logs_annulled_by_user_id_foreign')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                if (Schema::hasColumn('attendance_logs', 'annulled_by_user_id')) {
                    $table->foreign('annulled_by_user_id')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                }
            });
        }

        $indexes = [
            'attendance_logs_log_date_idx' => ['log_date'],
            'attendance_logs_employee_log_date_idx' => ['employee_id', 'log_date'],
            'attendance_logs_location_log_date_idx' => ['location_id', 'log_date'],
            'attendance_logs_device_log_date_idx' => ['device_id', 'log_date'],
            'attendance_logs_status_log_date_idx' => ['attendance_status', 'log_date'],
            'attendance_logs_source_log_date_idx' => ['source', 'log_date'],
        ];

        foreach ($indexes as $name => $columns) {
            if (! $this->indexExists('attendance_logs', $name)) {
                Schema::table('attendance_logs', function (Blueprint $table) use ($columns, $name): void {
                    $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        $indexes = [
            'attendance_logs_source_log_date_idx',
            'attendance_logs_status_log_date_idx',
            'attendance_logs_device_log_date_idx',
            'attendance_logs_location_log_date_idx',
            'attendance_logs_employee_log_date_idx',
            'attendance_logs_log_date_idx',
        ];

        foreach ($indexes as $index) {
            if ($this->indexExists('attendance_logs', $index)) {
                Schema::table('attendance_logs', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('attendance_logs', 'annulled_by_user_id')) {
                try {
                    $table->dropForeign(['annulled_by_user_id']);
                } catch (\Throwable $exception) {
                    // no-op
                }
            }

            if (Schema::hasColumn('attendance_logs', 'annulled_by_user_id')) {
                $table->dropColumn('annulled_by_user_id');
            }

            if (Schema::hasColumn('attendance_logs', 'annulled_at')) {
                $table->dropColumn('annulled_at');
            }

            if (Schema::hasColumn('attendance_logs', 'adjustment_reason')) {
                $table->dropColumn('adjustment_reason');
            }

            if (Schema::hasColumn('attendance_logs', 'attendance_status')) {
                $table->dropColumn('attendance_status');
            }

            if (Schema::hasColumn('attendance_logs', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
