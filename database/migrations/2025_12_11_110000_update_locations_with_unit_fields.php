<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (! Schema::hasColumn('locations', 'description')) {
                $table->string('description', 255)->nullable()->after('code');
            }

            if (! Schema::hasColumn('locations', 'city')) {
                $table->string('city', 120)->nullable()->after('description');
            }

            if (! Schema::hasColumn('locations', 'state')) {
                $table->string('state', 120)->nullable()->after('city');
            }

            if (! Schema::hasColumn('locations', 'country')) {
                $table->string('country', 120)->nullable()->after('state');
            }

            if (! Schema::hasColumn('locations', 'timezone')) {
                $table->string('timezone', 60)->nullable()->after('address');
            }

            $table->index('status');

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (Schema::hasColumn('locations', 'description')) {
                $table->dropColumn('description');
            }

            if (Schema::hasColumn('locations', 'city')) {
                $table->dropColumn('city');
            }

            if (Schema::hasColumn('locations', 'state')) {
                $table->dropColumn('state');
            }

            if (Schema::hasColumn('locations', 'country')) {
                $table->dropColumn('country');
            }

            if (Schema::hasColumn('locations', 'timezone')) {
                $table->dropColumn('timezone');
            }

            $table->dropIndex('locations_status_index');

            $table->dropUnique('locations_company_id_code_unique');
        });
    }
};
