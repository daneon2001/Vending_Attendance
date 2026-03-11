<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_nonces')) {
            return;
        }

        Schema::create('device_nonces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('nonce', 120);
            $table->dateTime('seen_at');
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['device_id', 'nonce'], 'device_nonces_device_nonce_unique');
            $table->index('expires_at', 'device_nonces_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_nonces');
    }
};

