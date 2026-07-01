<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_cleanup_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('critical_retention_days')->default((int) config('audit.cleanup.critical_retention_days', 3650));
            $table->unsignedSmallInteger('important_retention_days')->default((int) config('audit.cleanup.important_retention_days', 180));
            $table->unsignedSmallInteger('noise_retention_days')->default((int) config('audit.cleanup.noise_retention_days', 7));
            $table->unsignedInteger('batch_size')->default((int) config('audit.cleanup.batch_size', 5000));
            $table->unsignedSmallInteger('heartbeat_log_interval_minutes')->default((int) config('audit.cleanup.heartbeat_log_interval_minutes', 30));
            $table->unsignedInteger('optimize_min_deleted_mb')->default((int) config('audit.cleanup.optimize_min_deleted_mb', 512));
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->index('updated_by_user_id', 'audit_cleanup_settings_updated_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_cleanup_settings');
    }
};
