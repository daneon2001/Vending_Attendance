<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('employee_sync_states');

        Schema::create('employee_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('source')->unique();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status', 20)->nullable();
            $table->text('last_sync_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sync_states');
    }
};
