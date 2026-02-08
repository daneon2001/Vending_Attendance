<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clocks')) {
            return;
        }

        Schema::table('clocks', function (Blueprint $table): void {
            if (! Schema::hasColumn('clocks', 'last_seen_ip')) {
                $table->string('last_seen_ip', 45)->nullable()->after('last_status_message');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clocks')) {
            return;
        }

        Schema::table('clocks', function (Blueprint $table): void {
            if (Schema::hasColumn('clocks', 'last_seen_ip')) {
                $table->dropColumn('last_seen_ip');
            }
        });
    }
};

