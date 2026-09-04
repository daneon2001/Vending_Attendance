<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('device_name')->nullable()->after('device_serial');
            $table->string('platform', 40)->nullable()->after('device_name');
            $table->string('platform_version', 80)->nullable()->after('platform');
            $table->string('app_version', 80)->nullable()->after('platform_version');
            $table->string('hardware_model', 120)->nullable()->after('app_version');
            $table->string('status', 24)->default('PENDING')->index()->after('hardware_model');
            $table->timestamp('provisioned_at')->nullable()->after('status');
            $table->timestamp('activated_at')->nullable()->after('provisioned_at');
            $table->timestamp('retired_at')->nullable()->after('activated_at');
            $table->unsignedBigInteger('config_version_applied')->nullable()->after('retired_at');
            $table->unsignedBigInteger('employee_manifest_version_applied')->nullable()->after('config_version_applied');
            $table->unsignedBigInteger('biometric_manifest_version_applied')->nullable()->after('employee_manifest_version_applied');
            $table->text('credential_secret')->nullable()->after('shared_secret');
            $table->unsignedBigInteger('credential_version')->default(0)->after('credential_secret');
            $table->timestamp('credential_issued_at')->nullable()->after('credential_version');
            $table->timestamp('credential_revoked_at')->nullable()->after('credential_issued_at');
            $table->unsignedBigInteger('active_vending_machine_id')->nullable()->unique()->after('credential_revoked_at');
            $table->decimal('battery_level', 5, 2)->nullable()->after('last_status');
            $table->unsignedBigInteger('storage_free_mb')->nullable()->after('battery_level');
            $table->unsignedInteger('pending_events_count')->nullable()->after('storage_free_mb');
            $table->timestamp('device_time')->nullable()->after('pending_events_count');
            $table->integer('clock_drift_seconds')->nullable()->after('device_time');
            $table->json('metadata')->nullable()->after('clock_drift_seconds');

            $table->index(['vending_machine_id', 'status'], 'devices_vending_status_idx');
            $table->index(['status', 'last_seen_at'], 'devices_status_seen_idx');
        });

        DB::table('devices')->orderBy('id')->chunkById(200, function ($devices): void {
            foreach ($devices as $device) {
                DB::table('devices')->where('id', $device->id)->update([
                    'uuid' => $device->uuid ?: (string) Str::uuid(),
                    'status' => $device->is_active ? 'ACTIVE' : 'SUSPENDED',
                ]);
            }
        });

        DB::table('devices')
            ->whereNotNull('vending_machine_id')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get()
            ->groupBy('vending_machine_id')
            ->each(function ($devices, $machineId): void {
                $primary = $devices->first();
                DB::table('devices')->where('id', $primary->id)->update([
                    'status' => 'ACTIVE',
                    'active_vending_machine_id' => $machineId,
                ]);

                $historicalIds = $devices->skip(1)->pluck('id');
                if ($historicalIds->isNotEmpty()) {
                    DB::table('devices')->whereIn('id', $historicalIds)->update([
                        'status' => 'RETIRED',
                        'is_active' => false,
                        'retired_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex('devices_vending_status_idx');
            $table->dropIndex('devices_status_seen_idx');
            $table->dropUnique('devices_active_vending_machine_id_unique');
            $table->dropUnique('devices_uuid_unique');
            $table->dropColumn([
                'uuid', 'device_name', 'platform', 'platform_version', 'app_version',
                'hardware_model', 'status', 'provisioned_at', 'activated_at',
                'retired_at', 'config_version_applied', 'employee_manifest_version_applied',
                'biometric_manifest_version_applied', 'credential_secret', 'credential_version',
                'credential_issued_at', 'credential_revoked_at', 'active_vending_machine_id',
                'battery_level', 'storage_free_mb', 'pending_events_count', 'device_time',
                'clock_drift_seconds', 'metadata',
            ]);
        });
    }
};
