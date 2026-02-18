<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendances_raw')) {
            return;
        }

        Schema::create('attendances_raw', function (Blueprint $table): void {
            $table->bigIncrements('remote_event_id');
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('device_serial', 120);
            $table->string('local_event_id', 120);
            $table->unsignedBigInteger('collaborator_id');
            $table->unsignedBigInteger('clock_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->dateTime('event_time_utc');
            $table->dateTime('event_time_local');
            $table->string('tz', 120);
            $table->string('type', 20);
            $table->string('source', 80)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['device_serial', 'local_event_id'], 'att_raw_device_local_unique');
            $table->index(['collaborator_id', 'event_time_utc'], 'att_raw_collab_utc_idx');
            $table->index(['unit_id', 'event_time_utc'], 'att_raw_unit_utc_idx');
            $table->index(['clock_id', 'event_time_utc'], 'att_raw_clock_utc_idx');
            $table->index('event_time_utc', 'att_raw_utc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances_raw');
    }
};

