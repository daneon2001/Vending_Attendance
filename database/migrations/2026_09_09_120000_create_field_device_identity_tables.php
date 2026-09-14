<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A row is an immutable device/employee binding, not a vending terminal.
        Schema::create('employee_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('operation_uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_employee_id')->nullable()->unique()->constrained('employees')->restrictOnDelete();
            $table->text('public_key');
            $table->char('key_fingerprint', 64)->unique();
            $table->unsignedInteger('key_version')->default(1);
            $table->string('platform', 16);
            $table->string('platform_version', 64);
            $table->string('app_version', 64);
            $table->string('hardware_model', 120);
            $table->string('status', 16)->default('PENDING');
            $table->text('verified_phone'); // Encrypted, never serialized.
            $table->string('phone_verification_method', 32)->default('LOCAL_SIMULATED');
            $table->dateTime('phone_verified_at');
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->foreignId('replaces_id')->nullable()->constrained('employee_devices')->restrictOnDelete();
            $table->char('request_hash', 64);
            $table->timestamps();
            $table->index(['employee_id', 'status', 'id']);
        });
        Schema::create('field_device_otps', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->text('phone');
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('sends')->default(0);
            $table->dateTime('send_window_at');
            $table->dateTime('sent_at');
            $table->dateTime('expires_at');
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
        });
        Schema::create('field_device_challenges', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->foreignId('employee_device_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 16);
            $table->text('message');
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['employee_device_id', 'created_at']);
        });
        Schema::create('field_device_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('event', 48);
            $table->uuid('device_uuid')->nullable();
            $table->dateTime('occurred_at');
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['employee_devices', 'field_device_audit_events', 'field_device_otps', 'field_device_challenges'] as $table) {
            if (DB::table($table)->exists()) {
                throw new LogicException('Identity history exists; automatic rollback is forbidden.');
            }
        }
        Schema::drop('field_device_audit_events');
        Schema::drop('field_device_challenges');
        Schema::drop('field_device_otps');
        Schema::drop('employee_devices');
    }
};
