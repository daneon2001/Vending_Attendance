<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('razones_sociales', function (Blueprint $table) {
            $table->id();
            $table->string('cla_razon_social', 50)->unique();
            $table->string('nom_razon_social', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razones_sociales');
    }
};
