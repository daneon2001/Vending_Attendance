<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_manifest_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('manifest_type', 32);
            $table->unsignedBigInteger('applied_version')->nullable();
            $table->char('applied_hash', 64)->nullable();
            $table->string('last_ack_status', 16)->nullable();
            $table->unsignedBigInteger('last_ack_version')->nullable();
            $table->char('last_ack_hash', 64)->nullable();
            $table->timestamp('last_ack_at')->nullable();
            $table->timestamp('reported_applied_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'manifest_type'], 'device_manifest_states_device_type_unique');
            $table->index(['manifest_type', 'last_ack_status'], 'device_manifest_states_type_status_idx');
        });

        DB::table('devices')->orderBy('id')->eachById(function (object $device): void {
            $now = now();
            DB::table('device_manifest_states')->insert([
                [
                    'device_id' => $device->id,
                    'manifest_type' => 'CONFIGURATION',
                    'applied_version' => $device->config_version_applied,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'device_id' => $device->id,
                    'manifest_type' => 'EMPLOYEES',
                    'applied_version' => $device->employee_manifest_version_applied,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'device_id' => $device->id,
                    'manifest_type' => 'BIOMETRICS',
                    'applied_version' => $device->biometric_manifest_version_applied,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_manifest_states');
    }
};
