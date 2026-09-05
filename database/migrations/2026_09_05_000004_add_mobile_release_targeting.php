<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('release_group', 80)->nullable()->after('release_channel');
            $table->index(['release_channel', 'release_group'], 'devices_release_target_idx');
        });
        Schema::create('mobile_release_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mobile_release_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->string('target_value', 100);
            $table->timestamps();

            $table->unique(['mobile_release_id', 'target_type', 'target_value'], 'mobile_release_target_uq');
            $table->index(['target_type', 'target_value'], 'mobile_release_target_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_release_targets');
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex('devices_release_target_idx');
            $table->dropColumn('release_group');
        });
    }
};
