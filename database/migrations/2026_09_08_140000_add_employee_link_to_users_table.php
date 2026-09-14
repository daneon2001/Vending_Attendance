<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->unique()
                ->constrained('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never silently discard established identity links during rollback.
        if (DB::table('users')->whereNotNull('employee_id')->exists()) {
            throw new RuntimeException('Cannot remove employee linkage while linked accounts exist.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['employee_id']);
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
