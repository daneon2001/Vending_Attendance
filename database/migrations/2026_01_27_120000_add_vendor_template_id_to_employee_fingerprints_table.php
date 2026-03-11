<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_fingerprints', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_fingerprints', 'vendor_template_id')) {
                $table->string('vendor_template_id', 191)
                    ->nullable()
                    ->after('clock_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_fingerprints', function (Blueprint $table) {
            if (Schema::hasColumn('employee_fingerprints', 'vendor_template_id')) {
                $table->dropColumn('vendor_template_id');
            }
        });
    }
};
