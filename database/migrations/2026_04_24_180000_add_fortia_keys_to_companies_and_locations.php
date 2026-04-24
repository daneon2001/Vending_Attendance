<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'fortia_company_id')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->unsignedBigInteger('fortia_company_id')->nullable()->after('id');
                $table->unique('fortia_company_id', 'companies_fortia_company_id_unique');
            });
        }

        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'fortia_location_id')) {
            Schema::table('locations', function (Blueprint $table): void {
                $table->unsignedBigInteger('fortia_location_id')->nullable()->after('id');
                $table->unique('fortia_location_id', 'locations_fortia_location_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('locations') && Schema::hasColumn('locations', 'fortia_location_id')) {
            Schema::table('locations', function (Blueprint $table): void {
                $table->dropUnique('locations_fortia_location_id_unique');
                $table->dropColumn('fortia_location_id');
            });
        }

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'fortia_company_id')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropUnique('companies_fortia_company_id_unique');
                $table->dropColumn('fortia_company_id');
            });
        }
    }
};
