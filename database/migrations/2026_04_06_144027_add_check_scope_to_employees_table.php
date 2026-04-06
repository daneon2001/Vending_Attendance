<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'check_scope')) {
                $table->string('check_scope', 30)
                    ->nullable()
                    ->after('can_check_all_branches');
            }
        });

        DB::statement("
            UPDATE employees
            SET check_scope = 'HOME_ONLY'
            WHERE check_scope IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'check_scope')) {
                $table->dropColumn('check_scope');
            }
        });
    }
};