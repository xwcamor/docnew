<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cambió la semántica del coloreo de celdas: el rojo (fuera de norma) ahora se
 * muestra SIEMPRE, y este setting solo filtra el amarillo de precaución. El
 * valor viejo (60) ocultaba el amarillo de aviso; se resetea a 0 (mostrar todo)
 * para que el aviso temprano sea visible por defecto. Solo afecta qué se
 * resalta, no el diagnóstico.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->where('key', 'diagnostics.cell_alert_sev')->update(['value' => '0']);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'diagnostics.cell_alert_sev')->update(['value' => '60']);
    }
};
