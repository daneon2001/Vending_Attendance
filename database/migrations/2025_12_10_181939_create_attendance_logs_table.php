<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('log_id'); // LogId de la API
            $table->integer('company_id');
            $table->integer('employee_id');       // EmployeeId Fortia-like (employee_code)
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->dateTime('log_date');         // yyyyMMddHHmmss -> datetime
            $table->tinyInteger('log_type')->default(0);
            $table->integer('function_int')->nullable();
            $table->string('function_str', 50)->nullable();
            $table->tinyInteger('status')->nullable();     // 1 OK, 2 error
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'log_date'], 'idx_company_employee_date');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
