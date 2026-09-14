<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vending_support_activities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('support_ticket_id')->nullable()->constrained()->restrictOnDelete();
            foreach (['assigned_by_user_id', 'created_by_user_id', 'started_by_user_id', 'completed_by_user_id', 'cancelled_by_user_id'] as $column) {
                $table->foreignId($column)->nullable()->constrained('users')->restrictOnDelete();
            }
            $table->string('activity_type', 32);
            $table->string('status', 24);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('presence_policy', 32);
            $table->boolean('requires_physical_presence');
            foreach (['scheduled_at', 'started_at', 'completed_at', 'cancelled_at', 'started_captured_at', 'geofence_evaluated_at'] as $column) {
                $table->dateTime($column)->nullable();
            }
            $table->string('cancellation_reason', 1000)->nullable();
            $table->decimal('started_latitude', 10, 7)->nullable();
            $table->decimal('started_longitude', 10, 7)->nullable();
            $table->decimal('started_accuracy_m', 12, 3)->nullable();
            $table->foreignId('machine_geofence_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('geofence_version')->nullable();
            $table->decimal('distance_m', 14, 2)->nullable();
            $table->decimal('effective_distance_m', 14, 2)->nullable();
            $table->string('geofence_result', 24)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'id'], 'support_activity_employee_status');
            $table->index(['vending_machine_id', 'status', 'id'], 'support_activity_machine_status');
        });
        // Durable atomic audit, separate from tickets and best-effort general audit.
        Schema::create('vending_support_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained('vending_support_activities')->restrictOnDelete();
            $table->string('kind', 32);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->unique(['activity_id', 'kind'], 'support_activity_event_once');
        });
    }

    public function down(): void
    {
        if (DB::table('vending_support_activities')->exists() || DB::table('vending_support_activity_events')->exists()) {
            throw new RuntimeException('Cannot remove operational activity history.');
        }
        Schema::dropIfExists('vending_support_activity_events');
        Schema::dropIfExists('vending_support_activities');
    }
};
