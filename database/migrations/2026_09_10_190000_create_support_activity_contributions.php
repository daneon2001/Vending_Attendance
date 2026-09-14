<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['support_activity_notes', 'support_activity_evidence'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('support_activity_id')->constrained('vending_support_activities')->restrictOnDelete();
                $table->foreignId('employee_id')->constrained()->restrictOnDelete();
                $table->foreignId('field_mobile_device_id')->constrained('employee_devices')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->dateTime('captured_at');
                $table->timestamps();
                $table->index(['support_activity_id', 'id'], $name.'_activity');
                if ($name === 'support_activity_notes') {
                    $table->text('body');

                    return;
                }
                $table->string('type', 16);
                $table->string('status', 16);
                $table->string('disk', 64);
                $table->string('storage_key');
                $table->string('thumbnail_key');
                $table->string('safe_filename', 64);
                $table->string('mime', 64);
                $table->unsignedInteger('size_bytes');
                $table->char('sha256', 64);
                $table->char('upload_sha256', 64);
                $table->string('thumbnail_mime', 64);
                $table->char('thumbnail_sha256', 64);
                $table->string('sanitization_version', 32);
                $table->dateTime('confirmed_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['support_activity_notes', 'support_activity_evidence'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove operational contribution history.');
            }
        }
        Schema::dropIfExists('support_activity_evidence');
        Schema::dropIfExists('support_activity_notes');
    }
};
