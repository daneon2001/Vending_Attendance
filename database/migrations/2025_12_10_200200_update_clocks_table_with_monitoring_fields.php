<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clocks', function (Blueprint $table) {
            $table->string('serial_number')->nullable()->after('clock_name');
            $table->string('firmware_version')->nullable()->after('serial_number');
            $table->timestamp('last_heartbeat_at')->nullable()->after('status');
            $table->string('last_status_message')->nullable()->after('last_heartbeat_at');
            $table->string('monitoring_status', 20)->default('offline')->after('last_status_message');

            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clocks', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['location_id']);

            $table->dropColumn([
                'serial_number',
                'firmware_version',
                'last_heartbeat_at',
                'last_status_message',
                'monitoring_status',
            ]);
        });
    }
};
