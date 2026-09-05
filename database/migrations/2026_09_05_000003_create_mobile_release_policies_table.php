<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_release_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 20);
            $table->string('channel', 20);
            $table->foreignId('current_release_id')->nullable()->constrained('mobile_releases')->nullOnDelete();
            $table->foreignId('recommended_release_id')->nullable()->constrained('mobile_releases')->nullOnDelete();
            $table->foreignId('minimum_release_id')->nullable()->constrained('mobile_releases')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'channel'], 'mobile_release_policy_scope_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_release_policies');
    }
};
