<?php

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::create('safety_probe', fn ($table) => $table->id());
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('safety_probe');
    }
};
