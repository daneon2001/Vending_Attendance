<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sybi_vending_sync_runs', function (Blueprint $table): void {
            $table->unsignedInteger('source_candidates')->default(0)->after('received');
            $table->unsignedInteger('source_created')->default(0)->after('source_candidates');
            $table->unsignedInteger('source_updated')->default(0)->after('source_created');
            $table->unsignedInteger('source_unchanged')->default(0)->after('source_updated');
            $table->unsignedInteger('source_invalid')->default(0)->after('source_unchanged');
            $table->unsignedInteger('operational_ready')->default(0)->after('source_invalid');
            $table->unsignedInteger('operational_created')->default(0)->after('operational_ready');
            $table->unsignedInteger('operational_updated')->default(0)->after('operational_created');
            $table->unsignedInteger('operational_unchanged')->default(0)->after('operational_updated');
            $table->unsignedInteger('operational_incomplete')->default(0)->after('operational_unchanged');
            $table->unsignedInteger('operational_conflicts')->default(0)->after('operational_incomplete');
            $table->unsignedInteger('operational_invalid')->default(0)->after('operational_conflicts');
            $table->unsignedInteger('operational_missing')->default(0)->after('operational_invalid');
        });
    }

    public function down(): void
    {
        Schema::table('sybi_vending_sync_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'source_candidates', 'source_created', 'source_updated', 'source_unchanged',
                'source_invalid', 'operational_ready', 'operational_created',
                'operational_updated', 'operational_unchanged', 'operational_incomplete',
                'operational_conflicts', 'operational_invalid', 'operational_missing',
            ]);
        });
    }
};
