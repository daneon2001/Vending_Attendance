<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_machine_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('vending_machine_id')->constrained()->restrictOnDelete();
            $table->string('assignment_type', 24);
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->boolean('attendance_allowed')->default(true);
            $table->boolean('enrollment_allowed')->default(false);
            $table->boolean('maintenance_allowed')->default(false);
            $table->string('status', 24)->default('ACTIVE');
            $table->string('source', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status', 'valid_from', 'valid_until'], 'assignments_employee_effective_idx');
            $table->index(['vending_machine_id', 'status', 'valid_from', 'valid_until'], 'assignments_machine_effective_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE employee_machine_assignments ADD CONSTRAINT assignments_valid_window_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_machine_assignments');
    }
};
