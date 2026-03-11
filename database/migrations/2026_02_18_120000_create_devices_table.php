<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices')) {
            return;
        }

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('device_serial', 120)->unique();
            $table->foreignId('clock_id')->nullable()->constrained('clocks')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('shared_secret', 255);
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'last_seen_at'], 'devices_active_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

