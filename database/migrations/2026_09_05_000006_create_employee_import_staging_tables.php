<?php

use App\Enums\Employees\EmployeeImportRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name', 191);
            $table->char('file_hash', 64)->index();
            $table->string('file_extension', 8);
            $table->string('status', 24)->default(EmployeeImportRunStatus::PREVIEW->value)->index();
            $table->json('detected_mapping')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_new')->default(0);
            $table->unsignedInteger('valid_update')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('conflicts')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_import_run_id')->constrained('employee_import_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('employee_number', 120)->nullable();
            $table->string('full_name')->nullable();
            $table->string('normalized_status', 20)->nullable();
            $table->string('classification', 32)->index();
            $table->json('errors')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->unique(['employee_import_run_id', 'row_number'], 'employee_import_rows_run_row_unique');
            $table->index(
                ['employee_import_run_id', 'classification'],
                'employee_import_rows_run_classification_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_rows');
        Schema::dropIfExists('employee_import_runs');
    }
};
