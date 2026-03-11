<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_logs', 'fortia_employee_id')) {
                $table->unsignedBigInteger('fortia_employee_id')
                    ->nullable()
                    ->after('employee_id')
                    ->index();
            }

            if (!Schema::hasColumn('attendance_logs', 'sent_to_fortia_at')) {
                $table->dateTime('sent_to_fortia_at')
                    ->nullable()
                    ->after('function_str');
            }

            if (!Schema::hasColumn('attendance_logs', 'fortia_status')) {
                $table->integer('fortia_status')
                    ->nullable()
                    ->after('sent_to_fortia_at');
            }

            if (!Schema::hasColumn('attendance_logs', 'fortia_response_payload')) {
                $table->json('fortia_response_payload')
                    ->nullable()
                    ->after('fortia_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_logs', 'fortia_response_payload')) {
                $table->dropColumn('fortia_response_payload');
            }

            if (Schema::hasColumn('attendance_logs', 'fortia_status')) {
                $table->dropColumn('fortia_status');
            }

            if (Schema::hasColumn('attendance_logs', 'sent_to_fortia_at')) {
                $table->dropColumn('sent_to_fortia_at');
            }

            if (Schema::hasColumn('attendance_logs', 'fortia_employee_id')) {
                $table->dropIndex(['fortia_employee_id']);
                $table->dropColumn('fortia_employee_id');
            }
        });
    }
};
