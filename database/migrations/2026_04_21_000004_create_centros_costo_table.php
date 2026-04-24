<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centros_costo', function (Blueprint $table) {
            $table->id();
            $table->string('cla_centro_costo', 50)->unique();
            $table->string('nom_centro_costo', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centros_costo');
    }
};
