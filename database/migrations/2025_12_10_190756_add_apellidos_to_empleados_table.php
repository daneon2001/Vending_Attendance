<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('empleados') || Schema::hasColumn('empleados', 'apellidos')) {
            return;
        }

        Schema::table('empleados', function (Blueprint $table) {
            $table->string('apellidos')
                  ->nullable()
                  ->after('nombre'); // opcional, solo por orden
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('empleados') || ! Schema::hasColumn('empleados', 'apellidos')) {
            return;
        }

        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn('apellidos');
        });
    }
};
