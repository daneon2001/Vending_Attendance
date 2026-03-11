<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Primero las dependientes
        Schema::dropIfExists('asistencias');
        Schema::dropIfExists('fingerprints');
        Schema::dropIfExists('employee_sync_states');

        // Luego la tabla padre
        Schema::dropIfExists('empleados');
    }

    public function down(): void
    {
        // Normalmente no se reconstruyen tablas legacy.
        // Si quisieras revertir, aquí tocaría recrearlas (no recomendado).
    }
};
