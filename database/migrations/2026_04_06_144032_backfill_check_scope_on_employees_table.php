<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'check_scope')) {
            return;
        }

        DB::statement("
            UPDATE employees
            SET check_scope = CASE
                WHEN can_check_all_branches = 1 THEN 'ANY_BRANCH'
                ELSE 'HOME_ONLY'
            END
            WHERE check_scope IS NULL
               OR check_scope NOT IN ('HOME_ONLY', 'ANY_BRANCH', 'SELECTED_BRANCHES')
        ");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('employees', 'check_scope')) {
            return;
        }

        DB::statement("
            UPDATE employees
            SET check_scope = NULL
        ");
    }
};