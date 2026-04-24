<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_imss', function (Blueprint $table) {
            $table->id();
            $table->string('cla_reg_imss', 50)->unique();
            $table->string('nom_reg_imss', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_imss');
    }
};
