<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_dailies')) {
            return;
        }

        Schema::create('attendance_dailies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->date('work_date');
            $table->dateTime('first_check_in_at')->nullable();
            $table->dateTime('last_check_out_at')->nullable();
            $table->unsignedSmallInteger('total_logs')->default(0);
            $table->unsignedSmallInteger('total_in')->default(0);
            $table->unsignedSmallInteger('total_out')->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->boolean('is_absence')->default(false);
            $table->string('consolidation_status', 30)->default('pending');
            $table->json('consolidated_payload')->nullable();
            $table->dateTime('consolidated_at')->nullable();
            $table->timestamps();

            $table->index('work_date', 'attendance_dailies_work_date_idx');
            $table->index(['employee_id', 'work_date'], 'attendance_dailies_employee_work_date_idx');
            $table->index(['location_id', 'work_date'], 'attendance_dailies_location_work_date_idx');
            $table->index(['company_id', 'work_date'], 'attendance_dailies_company_work_date_idx');
            $table->unique(['employee_id', 'work_date', 'location_id'], 'attendance_dailies_employee_workday_unit_unique');
        });

        if (Schema::hasTable('employees')) {
            Schema::table('attendance_dailies', function (Blueprint $table): void {
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('locations')) {
            Schema::table('attendance_dailies', function (Blueprint $table): void {
                $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_dailies')) {
            return;
        }

        Schema::table('attendance_dailies', function (Blueprint $table): void {
            try {
                $table->dropForeign(['employee_id']);
            } catch (\Throwable $exception) {
                // no-op
            }

            try {
                $table->dropForeign(['location_id']);
            } catch (\Throwable $exception) {
                // no-op
            }
        });

        Schema::dropIfExists('attendance_dailies');
    }
};
