<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Activa Factor de Potencia en el Índice de Salud (hi_enabled=true) en la base
 * existente. Decisión del usuario: fpot entra al HI como las demás pruebas.
 * El seeder ya trae el default en true para instalaciones nuevas.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('tests')->where('code', 'fpot')->update(['hi_enabled' => true]);
    }

    public function down(): void
    {
        DB::table('tests')->where('code', 'fpot')->update(['hi_enabled' => false]);
    }
};
