<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::connection('fortia_mock')->hasTable('fortia_employees')) {
            return;
        }

        Schema::connection('fortia_mock')->create('fortia_employees', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->string('company_name');
            $table->unsignedBigInteger('employee_id');
            $table->string('name');
            $table->string('last_name');
            $table->string('second_last_name')->nullable();
            $table->string('status', 20);
            $table->integer('base_location_id');
            $table->string('base_location_name');
            $table->integer('department_id');
            $table->string('department_name');
            $table->string('rfc')->nullable();
            $table->string('imss_number')->nullable();
            $table->string('curp')->nullable();
            $table->string('email_company')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id'], 'fortia_employees_company_employee_unique');
            $table->index('status');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::connection('fortia_mock')->dropIfExists('fortia_employees');
    }
};
