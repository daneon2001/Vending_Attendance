<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_releases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('platform', 20);
            $table->string('channel', 20);
            $table->string('version', 40);
            $table->unsignedBigInteger('build_number');
            $table->string('status', 20)->default('DRAFT');
            $table->string('minimum_os', 80)->nullable();
            $table->text('artifact_url')->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->boolean('mandatory')->default(false);
            $table->unsignedTinyInteger('rollout_percentage')->default(0);
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'channel', 'version', 'build_number'], 'mobile_release_identity_uq');
            $table->index(['platform', 'channel', 'status', 'released_at'], 'mobile_release_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_releases');
    }
};
