<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees') || Schema::hasTable('employee_import_metadata')) {
            return;
        }

        Schema::create('employee_import_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('source', 40)->default('employees_excel');
            $table->string('source_file_name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_metadata');
    }
};
