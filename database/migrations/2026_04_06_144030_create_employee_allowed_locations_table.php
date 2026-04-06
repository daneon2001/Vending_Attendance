<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_allowed_locations')) {
            Schema::create('employee_allowed_locations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('location_id');
                $table->timestamps();

                $table->unique(['employee_id', 'location_id'], 'employee_allowed_locations_unique');
                $table->index('employee_id', 'employee_allowed_locations_employee_idx');
                $table->index('location_id', 'employee_allowed_locations_location_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_allowed_locations');
    }
};