<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('locations') || ! Schema::hasColumn('locations', 'timezone')) {
            return;
        }

        DB::table('locations')
            ->whereNull('timezone')
            ->update(['timezone' => 'America/Mexico_City']);
    }

    public function down(): void
    {
        // no-op
    }
};

