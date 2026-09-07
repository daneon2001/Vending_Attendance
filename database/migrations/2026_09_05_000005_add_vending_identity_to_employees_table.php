<?php

use App\Enums\Employees\EmployeeSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employee_number', 120)->nullable()->after('fortia_employee_id');
            $table->string('source', 20)->default(EmployeeSource::LEGACY->value)->after('employee_number');
            $table->string('source_external_id', 191)->nullable()->after('source');
            $table->timestamp('source_synced_at')->nullable()->after('source_external_id');
            $table->timestamp('source_updated_at')->nullable()->after('source_synced_at');
        });

        DB::table('employees')
            ->whereNull('employee_number')
            ->whereNotNull('fortia_employee_id')
            ->update(['employee_number' => DB::raw('CAST(fortia_employee_id AS CHAR)')]);

        Schema::table('employees', function (Blueprint $table): void {
            $table->unique('employee_number', 'employees_employee_number_unique');
            $table->unique(['source', 'source_external_id'], 'employees_source_external_unique');
            $table->index(['source', 'status'], 'employees_source_status_index');
            $table->unsignedBigInteger('fortia_employee_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->whereNull('fortia_employee_id')->exists()) {
            throw new RuntimeException('Cannot roll back employee identity while non-Fortia employees exist.');
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_employee_number_unique');
            $table->dropUnique('employees_source_external_unique');
            $table->dropIndex('employees_source_status_index');
            $table->dropColumn([
                'employee_number', 'source', 'source_external_id',
                'source_synced_at', 'source_updated_at',
            ]);
            $table->unsignedBigInteger('fortia_employee_id')->nullable(false)->change();
        });
    }
};
