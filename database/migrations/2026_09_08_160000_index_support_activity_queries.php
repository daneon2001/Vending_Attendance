<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vending_support_activities', function (Blueprint $table): void {
            // MySQL EXPLAIN on 10,007 isolated activities showed ALL/filesort
            // for status, open work and date lists before these indexes.
            $table->index(['status', 'created_at', 'id'], 'support_activity_status_date');
            $table->index(['created_at', 'id'], 'support_activity_date');
        });
    }

    public function down(): void
    {
        Schema::table('vending_support_activities', function (Blueprint $table): void {
            $table->dropIndex('support_activity_status_date');
            $table->dropIndex('support_activity_date');
        });
    }
};
