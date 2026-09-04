<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_provisioning_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['vending_machine_id', 'expires_at'], 'provisioning_tokens_machine_expiry_idx');
            $table->index(['used_at', 'revoked_at'], 'provisioning_tokens_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_provisioning_tokens');
    }
};
