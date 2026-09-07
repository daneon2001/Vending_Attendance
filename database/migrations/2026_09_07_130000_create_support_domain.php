<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_integrations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('system_key', 64)->unique();
            $t->string('name', 160);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('support_integration_machines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('support_integration_id')->constrained()->restrictOnDelete();
            $t->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $t->unique(['support_integration_id', 'vending_machine_id'], 'support_integration_machine_unique');
        });
        Schema::create('support_policy_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('version')->unique();
            $t->string('label', 160);
            $t->boolean('is_demo')->default(true);
            $t->boolean('active')->default(false);
            $t->timestamp('valid_from');
            $t->timestamp('valid_until')->nullable();
            $t->json('payload');
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['active', 'valid_from']);
        });
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $t->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('reporter_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('reporter_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->foreignId('integration_id')->nullable()->constrained('support_integrations')->restrictOnDelete();
            $t->string('external_reference', 160)->nullable();
            $t->string('source', 32);
            $t->string('category', 64);
            $t->string('severity', 16);
            $t->string('priority', 16);
            $t->string('status', 20)->default('OPEN');
            $t->string('title', 160);
            $t->text('description');
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reported_at');
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->text('resolution')->nullable();
            $t->json('location')->nullable();
            $t->json('geofence_context')->nullable();
            $t->foreignId('policy_version_id')->nullable()->constrained('support_policy_versions')->restrictOnDelete();
            $t->json('policy_snapshot')->nullable();
            $t->timestamp('response_due_at')->nullable();
            $t->timestamp('resolution_due_at')->nullable();
            $t->timestamp('first_response_at')->nullable();
            $t->boolean('response_breached')->default(false);
            $t->boolean('resolution_breached')->default(false);
            $t->boolean('response_warned')->default(false);
            $t->boolean('resolution_warned')->default(false);
            $t->timestamps();
            $t->unique(['integration_id', 'external_reference'], 'support_external_reference_unique');
            $t->index(['status', 'updated_at', 'id'], 'support_status_queue');
            $t->index(['vending_machine_id', 'updated_at', 'id'], 'support_machine_queue');
            $t->index(['device_id', 'id'], 'support_device_queue');
            $t->index(['reporter_user_id', 'id'], 'support_reporter_queue');
            $t->index(['assignee_id', 'status', 'id'], 'support_assignee_queue');
            $t->index(['response_breached', 'response_due_at'], 'support_response_due');
            $t->index(['resolution_breached', 'resolution_due_at'], 'support_resolution_due');
        });
        Schema::create('support_runtime_cursors', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
        DB::table('support_runtime_cursors')->insert([
            ['key' => 'timeline_sequence', 'value' => 0],
            ['key' => 'fleet_device_id', 'value' => 0],
            ['key' => 'notification_sequence', 'value' => 0],
        ]);
        Schema::create('support_ticket_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('sequence')->unique();
            $t->foreignId('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $t->string('kind', 64);
            $t->string('actor_kind', 32);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('body')->nullable();
            $t->json('metadata');
            $t->boolean('public')->default(true);
            $t->timestamp('created_at');
            $t->index(['ticket_id', 'id']);
        });
        Schema::create('support_operations', function (Blueprint $t) {
            $t->id();
            $t->string('principal_key', 100);
            $t->uuid('operation_uuid');
            $t->char('request_hash', 64);
            $t->json('result')->nullable();
            $t->timestamp('created_at');
            $t->unique(['principal_key', 'operation_uuid'], 'support_operation_unique');
        });
        Schema::create('support_evidence', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $t->string('actor_kind', 32);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('declared_mime', 64);
            $t->unsignedBigInteger('declared_size');
            $t->char('upload_sha256', 64);
            $t->string('mime', 64)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->string('disk', 64)->default('support_private');
            $t->string('storage_key')->nullable();
            $t->string('safe_filename', 100)->nullable();
            $t->string('thumbnail_key')->nullable();
            $t->char('thumbnail_sha256', 64)->nullable();
            $t->string('thumbnail_mime', 64)->nullable();
            $t->string('status', 16)->default('PENDING');
            $t->timestamp('captured_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('sanitization_version', 32)->nullable();
            $t->timestamps();
            $t->index(['ticket_id', 'status']);
        });
        Schema::create('support_verifications', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->string('actor_kind', 32);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('app_version', 80)->nullable();
            $t->unsignedBigInteger('app_build_number')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('completed_at');
            $t->string('summary', 20);
            $t->json('checks');
            $t->timestamps();
            $t->index(['device_id', 'created_at']);
            $t->index(['vending_machine_id', 'created_at'], 'support_verification_machine');
        });
        Schema::create('support_correlations', function (Blueprint $t) {
            $t->id();
            $t->char('correlation_key', 64)->unique();
            $t->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->string('rule_key', 64);
            $t->foreignId('ticket_id')->nullable()->constrained('support_tickets')->restrictOnDelete();
            $t->foreignId('policy_version_id')->nullable()->constrained('support_policy_versions')->restrictOnDelete();
            $t->string('signal_state', 20);
            $t->timestamp('first_observed_at');
            $t->timestamp('last_observed_at');
            $t->timestamp('last_active_at')->nullable();
            $t->timestamp('recovered_at')->nullable();
            $t->timestamp('cooldown_until')->nullable();
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'support_notification_unread');
        });
    }

    public function down(): void
    {
        // Explicit rollback only; never invoked by operational support commands.
        foreach (['notifications', 'support_correlations', 'support_verifications', 'support_evidence',
            'support_operations', 'support_ticket_events', 'support_runtime_cursors', 'support_tickets',
            'support_policy_versions', 'support_integration_machines', 'support_integrations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
