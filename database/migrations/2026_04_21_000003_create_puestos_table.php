<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puestos', function (Blueprint $table) {
            $table->id();
            $table->string('cla_puesto', 50)->unique();
            $table->string('nom_puesto', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puestos');
    }
};
