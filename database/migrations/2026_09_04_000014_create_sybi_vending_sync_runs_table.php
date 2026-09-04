<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sybi_vending_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->dateTime('started_at', 6);
            $table->dateTime('finished_at', 6)->nullable();
            $table->string('status', 40)->index();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('conflicts')->default(0);
            $table->unsignedInteger('missing')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->json('warnings')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sybi_vending_sync_runs');
    }
};
