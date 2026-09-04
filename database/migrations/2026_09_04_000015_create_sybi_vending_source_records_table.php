<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sybi_vending_source_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sybi_id')->unique();
            $table->string('identificador_vending', 100)->nullable()->index();
            $table->string('name')->nullable();
            $table->string('address_line')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->unsignedBigInteger('sybi_city_id')->nullable();
            $table->unsignedBigInteger('sybi_state_id')->nullable();
            $table->text('sybi_full_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('source_status', 32)->index();
            $table->string('validation_status', 40)->index();
            $table->json('validation_codes')->nullable();
            $table->dateTime('first_seen_at', 6);
            $table->dateTime('last_seen_at', 6)->index();
            $table->char('payload_hash', 64);
            $table->foreignId('promoted_vending_machine_id')
                ->nullable()
                ->unique()
                ->constrained('vending_machines')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sybi_vending_source_records');
    }
};
