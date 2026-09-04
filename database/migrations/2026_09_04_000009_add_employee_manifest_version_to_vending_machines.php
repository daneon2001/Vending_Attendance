<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vending_machines', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_manifest_version')->default(1)->after('config_version');
            $table->char('employee_manifest_state_hash', 64)->nullable()->after('employee_manifest_version');
        });
    }

    public function down(): void
    {
        Schema::table('vending_machines', function (Blueprint $table): void {
            $table->dropColumn(['employee_manifest_version', 'employee_manifest_state_hash']);
        });
    }
};
