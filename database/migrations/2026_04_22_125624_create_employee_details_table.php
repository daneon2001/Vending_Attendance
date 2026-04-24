<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
        {
            if (Schema::hasTable('employees') && !Schema::hasTable('employee_details')) {
                Schema::create('employee_details', function (Blueprint $table) {
                    $table->id();

                    $table->foreignId('employee_id')
                        ->constrained('employees')
                        ->cascadeOnDelete();

                    $table->string('cla_trab', 50)->nullable()->index();

                    $table->foreignId('razon_social_id')->nullable()->constrained('razones_sociales')->nullOnDelete();
                    $table->foreignId('registro_imss_id')->nullable()->constrained('registros_imss')->nullOnDelete();
                    $table->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete();
                    $table->foreignId('centro_costo_id')->nullable()->constrained('centros_costo')->nullOnDelete();
                    $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
                    $table->foreignId('departamento_id')->nullable()->constrained('departamentos')->nullOnDelete();
                    $table->foreignId('ubicacion_id')->nullable()->constrained('ubicaciones')->nullOnDelete();
                    $table->foreignId('periodo_pago_id')->nullable()->constrained('periodos_pago')->nullOnDelete();

                    $table->timestamps();

                    $table->unique('employee_id');
                });
            }
        }

        public function down(): void
        {
            Schema::dropIfExists('employee_details');
        }
};