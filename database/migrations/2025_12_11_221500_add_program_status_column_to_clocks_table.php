<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clocks', function (Blueprint $table) {
            if (! Schema::hasColumn('clocks', 'program_status')) {
                $table->string('program_status', 30)
                    ->default('offline')
                    ->after('monitoring_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clocks', function (Blueprint $table) {
            if (Schema::hasColumn('clocks', 'program_status')) {
                $table->dropColumn('program_status');
            }
        });
    }
};
