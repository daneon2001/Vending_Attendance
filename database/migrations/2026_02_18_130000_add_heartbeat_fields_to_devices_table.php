<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('devices')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table): void {
            if (! Schema::hasColumn('devices', 'last_heartbeat_at')) {
                $table->dateTime('last_heartbeat_at')->nullable()->after('last_seen_at');
            }

            if (! Schema::hasColumn('devices', 'last_status')) {
                $table->string('last_status', 500)->nullable()->after('last_heartbeat_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('devices')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table): void {
            if (Schema::hasColumn('devices', 'last_status')) {
                $table->dropColumn('last_status');
            }

            if (Schema::hasColumn('devices', 'last_heartbeat_at')) {
                $table->dropColumn('last_heartbeat_at');
            }
        });
    }
};
