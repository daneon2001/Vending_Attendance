<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrolment_audits') || Schema::hasColumn('enrolment_audits', 'metadata')) {
            return;
        }

        Schema::table('enrolment_audits', function (Blueprint $table): void {
            $table->json('metadata')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrolment_audits') || ! Schema::hasColumn('enrolment_audits', 'metadata')) {
            return;
        }

        Schema::table('enrolment_audits', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
    }
};
