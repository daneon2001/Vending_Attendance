<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();   // vínculo opcional a users
            $table->integer('company_id')->nullable();           // CompanyId Fortia-like
            $table->string('num_empleado', 20)->nullable();      // interno
            $table->string('employee_code', 50)->nullable();     // EmployeeId / CLA_TRAB (valor de checada)
            $table->string('nombre');
            $table->string('primer_apellido')->nullable();
            $table->string('segundo_apellido')->nullable();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->boolean('change_sucursal')->default(false);
            $table->tinyInteger('estatus')->default(1);          // 1 activo
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
