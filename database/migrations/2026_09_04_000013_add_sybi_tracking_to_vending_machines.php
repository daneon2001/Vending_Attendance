<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vending_machines', function (Blueprint $table): void {
            $table->string('source', 24)->default('LOCAL')->index()->after('sybi_id');
            $table->unsignedBigInteger('sybi_city_id')->nullable()->after('source');
            $table->unsignedBigInteger('sybi_state_id')->nullable()->after('sybi_city_id');
            $table->text('sybi_full_address')->nullable()->after('sybi_state_id');
            $table->dateTime('sybi_last_seen_at')->nullable()->index()->after('sybi_full_address');
            $table->string('sybi_sync_status', 32)->default('NEVER_SYNCED')->index()->after('sybi_last_seen_at');
            $table->boolean('geofence_review_required')->default(false)->index()->after('sybi_sync_status');
            $table->dateTime('sybi_coordinates_changed_at')->nullable()->after('geofence_review_required');
        });
    }

    public function down(): void
    {
        Schema::table('vending_machines', function (Blueprint $table): void {
            $table->dropColumn([
                'source', 'sybi_city_id', 'sybi_state_id', 'sybi_full_address',
                'sybi_last_seen_at', 'sybi_sync_status', 'geofence_review_required',
                'sybi_coordinates_changed_at',
            ]);
        });
    }
};
