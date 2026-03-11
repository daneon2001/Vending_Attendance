<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('employee_template_deletions')) {
            return;
        }

        Schema::create('employee_template_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('vendor', 80)->default('digitalpersona');
            $table->string('vendor_template_id', 191);
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->dateTime('deleted_at');
            $table->timestamps();

            $table->index(['vendor', 'vendor_template_id'], 'employee_template_deletions_vendor_template_idx');
            $table->index('deleted_at', 'employee_template_deletions_deleted_at_idx');
            $table->index('employee_id', 'employee_template_deletions_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_template_deletions');
    }
};
