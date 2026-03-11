<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('fortia_employee_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->dateTime('log_date');
            $table->integer('log_type')->default(0);
            $table->integer('function_int')->nullable();
            $table->string('function_str')->nullable();
            $table->dateTime('sent_to_fortia_at')->nullable();
            $table->integer('fortia_status')->nullable();
            $table->json('fortia_response_payload')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'log_date']);
            $table->index('sent_to_fortia_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
