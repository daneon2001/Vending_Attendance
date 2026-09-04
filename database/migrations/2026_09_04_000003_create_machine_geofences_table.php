<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_geofences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('shape', 16)->default('CIRCLE');
            $table->decimal('center_latitude', 10, 7);
            $table->decimal('center_longitude', 10, 7);
            $table->unsignedInteger('radius_m');
            $table->decimal('minimum_acceptable_accuracy_m', 8, 2)->nullable();
            $table->decimal('tolerance_m', 8, 2)->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status', 24)->default('DRAFT')->index();
            $table->string('source', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('active_machine_id')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['vending_machine_id', 'version']);
            $table->index(['vending_machine_id', 'status', 'valid_from', 'valid_until'], 'geofences_machine_effective_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE machine_geofences ADD CONSTRAINT geofences_latitude_check CHECK (center_latitude BETWEEN -90 AND 90)');
            DB::statement('ALTER TABLE machine_geofences ADD CONSTRAINT geofences_longitude_check CHECK (center_longitude BETWEEN -180 AND 180)');
            DB::statement('ALTER TABLE machine_geofences ADD CONSTRAINT geofences_center_check CHECK (NOT (center_latitude = 0 AND center_longitude = 0))');
            DB::statement('ALTER TABLE machine_geofences ADD CONSTRAINT geofences_radius_check CHECK (radius_m > 0)');
            DB::statement('ALTER TABLE machine_geofences ADD CONSTRAINT geofences_accuracy_check CHECK (minimum_acceptable_accuracy_m IS NULL OR minimum_acceptable_accuracy_m >= 0)');
            DB::statement('ALTER TABLE machine_geofences ADD CONSTRAINT geofences_tolerance_check CHECK (tolerance_m >= 0)');
            DB::statement('ALTER TABLE machine_geofences ADD CONSTRAINT geofences_valid_window_check CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from)');
            DB::statement("ALTER TABLE machine_geofences ADD CONSTRAINT geofences_active_lock_check CHECK ((status = 'ACTIVE' AND active_machine_id = vending_machine_id) OR (status <> 'ACTIVE' AND active_machine_id IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_geofences');
    }
};
