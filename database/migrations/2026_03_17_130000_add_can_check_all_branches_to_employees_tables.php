<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'can_check_all_branches')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->boolean('can_check_all_branches')->default(false)->after('base_location_name');
            });
        }

        if (
            Schema::connection('fortia_mock')->hasTable('fortia_employees')
            && ! Schema::connection('fortia_mock')->hasColumn('fortia_employees', 'can_check_all_branches')
        ) {
            Schema::connection('fortia_mock')->table('fortia_employees', function (Blueprint $table): void {
                $table->boolean('can_check_all_branches')->default(false)->after('base_location_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'can_check_all_branches')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('can_check_all_branches');
            });
        }

        if (
            Schema::connection('fortia_mock')->hasTable('fortia_employees')
            && Schema::connection('fortia_mock')->hasColumn('fortia_employees', 'can_check_all_branches')
        ) {
            Schema::connection('fortia_mock')->table('fortia_employees', function (Blueprint $table): void {
                $table->dropColumn('can_check_all_branches');
            });
        }
    }
};
