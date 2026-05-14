<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_face_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('fortia_employee_id')->nullable()->index();
            $table->string('employee_code')->nullable();
            $table->string('template_hash')->unique();
            $table->longText('embedding_encrypted');
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->string('model_name');
            $table->string('model_version');
            $table->string('source_device')->nullable();
            $table->string('source_serial')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_face_templates');
    }
};
