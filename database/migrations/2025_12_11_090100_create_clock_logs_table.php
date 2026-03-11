<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clock_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clock_id')->constrained('clocks')->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->string('level', 20)->default('info');
            $table->string('source')->nullable();
            $table->text('message');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['clock_id', 'occurred_at']);
            $table->index(['clock_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clock_logs');
    }
};
