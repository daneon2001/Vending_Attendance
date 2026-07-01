<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_cleanup_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->string('trigger_source', 40)->default('manual');
            $table->string('status', 20)->default('completed');
            $table->string('mode', 20)->default('cleanup');
            $table->json('settings_snapshot')->nullable();
            $table->json('filters_snapshot')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('deleted_records')->default(0);
            $table->unsignedBigInteger('estimated_bytes_freed')->default(0);
            $table->boolean('optimized')->default(false);
            $table->boolean('optimize_statement_ran')->default(false);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'audit_cleanup_runs_status_created_idx');
            $table->index(['trigger_source', 'created_at'], 'audit_cleanup_runs_source_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_cleanup_runs');
    }
};
