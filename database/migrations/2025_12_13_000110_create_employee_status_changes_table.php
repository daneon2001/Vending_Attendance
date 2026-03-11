<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->integer('company_id');
            $table->unsignedBigInteger('fortia_employee_id');
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->dateTime('changed_at');
            $table->string('source', 50)->default('fortia_mock');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fortia_employee_id']);
            $table->index('changed_at');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_changes');
    }
};
