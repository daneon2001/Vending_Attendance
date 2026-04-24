<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos_pago', function (Blueprint $table) {
            $table->id();
            $table->string('cla_periodo_pago', 50)->unique();
            $table->string('nom_periodo_pago', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_pago');
    }
};
