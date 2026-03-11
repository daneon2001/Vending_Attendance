<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_changes')) {
            return;
        }

        Schema::create('attendance_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attendance_log_id')->nullable();
            $table->string('action', 40);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('changed_by_name')->nullable();
            $table->string('changed_by_email')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['attendance_log_id', 'created_at'], 'attendance_changes_record_created_idx');
            $table->index(['action', 'created_at'], 'attendance_changes_action_created_idx');
            $table->index(['changed_by_user_id', 'created_at'], 'attendance_changes_user_created_idx');
        });

        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_changes', function (Blueprint $table): void {
                $table->foreign('attendance_log_id')
                    ->references('id')
                    ->on('attendance_logs')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('attendance_changes', function (Blueprint $table): void {
                $table->foreign('changed_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_changes')) {
            return;
        }

        Schema::table('attendance_changes', function (Blueprint $table): void {
            try {
                $table->dropForeign(['attendance_log_id']);
            } catch (\Throwable $exception) {
                // no-op
            }

            try {
                $table->dropForeign(['changed_by_user_id']);
            } catch (\Throwable $exception) {
                // no-op
            }
        });

        Schema::dropIfExists('attendance_changes');
    }
};
