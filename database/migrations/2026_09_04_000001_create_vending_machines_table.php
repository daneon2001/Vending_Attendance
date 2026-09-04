<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vending_machines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('sybi_id')->nullable()->unique();
            $table->string('machine_code', 100)->unique();
            $table->string('operational_code', 100)->nullable()->index();
            $table->string('name')->nullable();
            $table->string('address_line')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('locality')->nullable()->index();
            $table->string('municipality')->nullable()->index();
            $table->string('state')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2)->default('MX');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('coordinate_source', 32)->nullable();
            $table->boolean('coordinates_verified')->default(false);
            $table->timestamp('coordinates_verified_at')->nullable();
            $table->string('timezone', 64)->default('America/Mexico_City');
            $table->string('status', 24)->default('DRAFT')->index();
            $table->unsignedInteger('default_geofence_radius_m')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('config_version')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE vending_machines ADD CONSTRAINT vending_machines_latitude_check CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE vending_machines ADD CONSTRAINT vending_machines_longitude_check CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE vending_machines ADD CONSTRAINT vending_machines_coordinates_verified_check CHECK (coordinates_verified = 0 OR (latitude IS NOT NULL AND longitude IS NOT NULL AND NOT (latitude = 0 AND longitude = 0)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vending_machines');
    }
};
