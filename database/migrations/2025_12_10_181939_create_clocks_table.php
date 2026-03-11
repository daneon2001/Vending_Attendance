<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clocks', function (Blueprint $table) {
            $table->id();
            // De momento SIN FK, solo la columna:
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('clock_name');
            $table->string('ip_address', 45)->nullable();
            $table->string('type_inout', 50)->nullable();
            $table->tinyInteger('status')->default(1);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->timestamps();
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clocks');
    }
};
