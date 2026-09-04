<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vending_attendance_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique('vending_attendance_events_uuid_unique');
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_machine_assignment_id')->nullable()
                ->constrained('employee_machine_assignments')->nullOnDelete();
            $table->string('assignment_uuid_snapshot', 36);
            $table->string('event_type', 24);
            $table->dateTime('captured_at_device', 6);
            $table->dateTime('received_at_server', 6);
            $table->string('device_timezone', 64)->nullable();
            $table->integer('device_clock_drift_seconds')->nullable();
            $table->bigInteger('sync_delay_seconds');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->decimal('accuracy_m', 10, 2)->nullable();
            $table->string('location_evidence_status', 24);

            $table->foreignId('geofence_id')->nullable()->constrained('machine_geofences')->nullOnDelete();
            $table->unsignedBigInteger('geofence_version')->nullable();
            $table->string('edge_geofence_result', 24)->nullable();
            $table->string('server_geofence_result', 24);
            $table->decimal('distance_m', 12, 3)->nullable();
            $table->decimal('effective_distance_m', 12, 3)->nullable();
            $table->boolean('geofence_discrepancy')->default(false);

            $table->string('authorization_result', 24);
            $table->string('authorization_reason', 64)->nullable();
            $table->unsignedBigInteger('employee_manifest_version');
            $table->string('employee_manifest_evidence_status', 16);
            $table->unsignedBigInteger('configuration_version');
            $table->string('configuration_evidence_status', 16);

            $table->string('employee_number_snapshot', 120)->nullable();
            $table->string('assignment_type_snapshot', 32)->nullable();
            $table->dateTime('assignment_valid_from_snapshot', 6)->nullable();
            $table->dateTime('assignment_valid_until_snapshot', 6)->nullable();
            $table->boolean('attendance_allowed_snapshot')->nullable();
            $table->string('machine_code_snapshot', 120);
            $table->decimal('geofence_radius_snapshot', 10, 2)->nullable();
            $table->decimal('geofence_latitude_snapshot', 10, 7)->nullable();
            $table->decimal('geofence_longitude_snapshot', 11, 7)->nullable();
            $table->decimal('geofence_tolerance_snapshot', 10, 2)->nullable();

            $table->string('biometric_result', 24)->nullable();
            $table->string('biometric_reference', 191)->nullable();
            $table->string('sync_status', 24);
            $table->char('payload_hash', 64);
            $table->json('metadata')->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['device_id', 'captured_at_device'], 'vend_att_device_captured_idx');
            $table->index(['vending_machine_id', 'captured_at_device'], 'vend_att_machine_captured_idx');
            $table->index(['employee_id', 'captured_at_device'], 'vend_att_employee_captured_idx');
            $table->index('received_at_server', 'vend_att_received_idx');
            $table->index(['server_geofence_result', 'received_at_server'], 'vend_att_geofence_received_idx');
            $table->index(['geofence_discrepancy', 'received_at_server'], 'vend_att_mismatch_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vending_attendance_events');
    }
};
