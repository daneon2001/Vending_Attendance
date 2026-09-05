<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->unsignedBigInteger('app_build_number')->nullable()->after('app_version');
            $table->string('release_channel', 20)->default('PRODUCTION')->after('app_build_number');
            $table->string('network_state', 20)->nullable()->after('last_status');
            $table->string('last_error_category', 32)->nullable()->after('network_state');
            $table->string('last_error_code', 100)->nullable()->after('last_error_category');
            $table->timestamp('last_error_at')->nullable()->after('last_error_code');

            $table->index(['platform', 'app_version'], 'devices_platform_app_version_idx');
            $table->index(['status', 'last_heartbeat_at'], 'devices_status_heartbeat_idx');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex('devices_platform_app_version_idx');
            $table->dropIndex('devices_status_heartbeat_idx');
            $table->dropColumn([
                'app_build_number', 'release_channel', 'network_state',
                'last_error_category', 'last_error_code', 'last_error_at',
            ]);
        });
    }
};
