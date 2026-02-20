<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateAuditLogsTable();
        $this->updateAttendanceLogsTable();
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                foreach ([
                    'attendance_logs_request_id_idx',
                    'attendance_logs_ingested_at_utc_idx',
                    'attendance_logs_device_serial_idx',
                    'attendance_logs_integrity_hash_idx',
                    'attendance_logs_integrity_prev_hash_idx',
                ] as $index) {
                    if (Schema::hasIndex('attendance_logs', $index)) {
                        $table->dropIndex($index);
                    }
                }

                foreach ([
                    'request_id',
                    'auth_key_id',
                    'device_serial',
                    'ingest_ip',
                    'ingested_at_utc',
                    'integrity_verified_at',
                    'integrity_hash_version',
                    'integrity_previous_hash',
                    'integrity_hash',
                ] as $column) {
                    if (Schema::hasColumn('attendance_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                foreach ([
                    'audit_logs_actor_user_created_idx',
                    'audit_logs_action_created_idx',
                    'audit_logs_entity_created_idx',
                    'audit_logs_request_id_idx',
                    'audit_logs_correlation_id_idx',
                    'audit_logs_device_created_idx',
                    'audit_logs_occurred_utc_idx',
                ] as $index) {
                    if (Schema::hasIndex('audit_logs', $index)) {
                        $table->dropIndex($index);
                    }
                }

                if (Schema::hasColumn('audit_logs', 'actor_user_id')) {
                    try {
                        $table->dropForeign(['actor_user_id']);
                    } catch (\Throwable $exception) {
                        // no-op
                    }
                }

                if (Schema::hasColumn('audit_logs', 'device_id')) {
                    try {
                        $table->dropForeign(['device_id']);
                    } catch (\Throwable $exception) {
                        // no-op
                    }
                }

                foreach ([
                    'timezone',
                    'occurred_at_local',
                    'occurred_at_utc',
                    'device_id',
                    'correlation_id',
                    'request_id',
                    'reason',
                    'new_values',
                    'old_values',
                    'entity_id',
                    'entity',
                    'action',
                    'actor_identifier',
                    'actor_type',
                    'actor_user_id',
                ] as $column) {
                    if (Schema::hasColumn('audit_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function updateAuditLogsTable(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('audit_logs', 'actor_user_id')) {
                $table->unsignedBigInteger('actor_user_id')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('audit_logs', 'actor_type')) {
                $table->string('actor_type', 40)->nullable()->after('actor_user_id');
            }
            if (! Schema::hasColumn('audit_logs', 'actor_identifier')) {
                $table->string('actor_identifier', 191)->nullable()->after('actor_type');
            }
            if (! Schema::hasColumn('audit_logs', 'action')) {
                $table->string('action', 40)->nullable()->after('event');
            }
            if (! Schema::hasColumn('audit_logs', 'entity')) {
                $table->string('entity', 120)->nullable()->after('action');
            }
            if (! Schema::hasColumn('audit_logs', 'entity_id')) {
                $table->string('entity_id', 120)->nullable()->after('entity');
            }
            if (! Schema::hasColumn('audit_logs', 'old_values')) {
                $table->json('old_values')->nullable()->after('metadata');
            }
            if (! Schema::hasColumn('audit_logs', 'new_values')) {
                $table->json('new_values')->nullable()->after('old_values');
            }
            if (! Schema::hasColumn('audit_logs', 'reason')) {
                $table->string('reason', 500)->nullable()->after('description');
            }
            if (! Schema::hasColumn('audit_logs', 'request_id')) {
                $table->string('request_id', 64)->nullable()->after('user_agent');
            }
            if (! Schema::hasColumn('audit_logs', 'correlation_id')) {
                $table->string('correlation_id', 64)->nullable()->after('request_id');
            }
            if (! Schema::hasColumn('audit_logs', 'device_id')) {
                $table->unsignedBigInteger('device_id')->nullable()->after('correlation_id');
            }
            if (! Schema::hasColumn('audit_logs', 'occurred_at_utc')) {
                $table->dateTime('occurred_at_utc')->nullable()->after('device_id');
            }
            if (! Schema::hasColumn('audit_logs', 'occurred_at_local')) {
                $table->dateTime('occurred_at_local')->nullable()->after('occurred_at_utc');
            }
            if (! Schema::hasColumn('audit_logs', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('occurred_at_local');
            }
        });

        if (Schema::hasTable('users')) {
            try {
                Schema::table('audit_logs', function (Blueprint $table): void {
                    if (Schema::hasColumn('audit_logs', 'actor_user_id')) {
                        $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
                    }
                });
            } catch (\Throwable $exception) {
                // no-op
            }
        }

        if (Schema::hasTable('devices')) {
            try {
                Schema::table('audit_logs', function (Blueprint $table): void {
                    if (Schema::hasColumn('audit_logs', 'device_id')) {
                        $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
                    }
                });
            } catch (\Throwable $exception) {
                // no-op
            }
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            if (! Schema::hasIndex('audit_logs', 'audit_logs_actor_user_created_idx')) {
                $table->index(['actor_user_id', 'created_at'], 'audit_logs_actor_user_created_idx');
            }
            if (! Schema::hasIndex('audit_logs', 'audit_logs_action_created_idx')) {
                $table->index(['action', 'created_at'], 'audit_logs_action_created_idx');
            }
            if (! Schema::hasIndex('audit_logs', 'audit_logs_entity_created_idx')) {
                $table->index(['entity', 'entity_id', 'created_at'], 'audit_logs_entity_created_idx');
            }
            if (! Schema::hasIndex('audit_logs', 'audit_logs_request_id_idx')) {
                $table->index('request_id', 'audit_logs_request_id_idx');
            }
            if (! Schema::hasIndex('audit_logs', 'audit_logs_correlation_id_idx')) {
                $table->index('correlation_id', 'audit_logs_correlation_id_idx');
            }
            if (! Schema::hasIndex('audit_logs', 'audit_logs_device_created_idx')) {
                $table->index(['device_id', 'created_at'], 'audit_logs_device_created_idx');
            }
            if (! Schema::hasIndex('audit_logs', 'audit_logs_occurred_utc_idx')) {
                $table->index('occurred_at_utc', 'audit_logs_occurred_utc_idx');
            }
        });
    }

    private function updateAttendanceLogsTable(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendance_logs', 'integrity_hash')) {
                $table->string('integrity_hash', 64)->nullable()->after('raw_payload');
            }
            if (! Schema::hasColumn('attendance_logs', 'integrity_previous_hash')) {
                $table->string('integrity_previous_hash', 64)->nullable()->after('integrity_hash');
            }
            if (! Schema::hasColumn('attendance_logs', 'integrity_hash_version')) {
                $table->unsignedSmallInteger('integrity_hash_version')->default(1)->after('integrity_previous_hash');
            }
            if (! Schema::hasColumn('attendance_logs', 'integrity_verified_at')) {
                $table->dateTime('integrity_verified_at')->nullable()->after('integrity_hash_version');
            }

            if (! Schema::hasColumn('attendance_logs', 'ingested_at_utc')) {
                $table->dateTime('ingested_at_utc')->nullable()->after('integrity_verified_at');
            }
            if (! Schema::hasColumn('attendance_logs', 'ingest_ip')) {
                $table->string('ingest_ip', 45)->nullable()->after('ingested_at_utc');
            }
            if (! Schema::hasColumn('attendance_logs', 'device_serial')) {
                $table->string('device_serial', 120)->nullable()->after('ingest_ip');
            }
            if (! Schema::hasColumn('attendance_logs', 'auth_key_id')) {
                $table->string('auth_key_id', 120)->nullable()->after('device_serial');
            }
            if (! Schema::hasColumn('attendance_logs', 'request_id')) {
                $table->string('request_id', 64)->nullable()->after('auth_key_id');
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (! Schema::hasIndex('attendance_logs', 'attendance_logs_integrity_hash_idx')) {
                $table->index('integrity_hash', 'attendance_logs_integrity_hash_idx');
            }
            if (! Schema::hasIndex('attendance_logs', 'attendance_logs_integrity_prev_hash_idx')) {
                $table->index('integrity_previous_hash', 'attendance_logs_integrity_prev_hash_idx');
            }
            if (! Schema::hasIndex('attendance_logs', 'attendance_logs_device_serial_idx')) {
                $table->index('device_serial', 'attendance_logs_device_serial_idx');
            }
            if (! Schema::hasIndex('attendance_logs', 'attendance_logs_ingested_at_utc_idx')) {
                $table->index('ingested_at_utc', 'attendance_logs_ingested_at_utc_idx');
            }
            if (! Schema::hasIndex('attendance_logs', 'attendance_logs_request_id_idx')) {
                $table->index('request_id', 'attendance_logs_request_id_idx');
            }
        });
    }
};
