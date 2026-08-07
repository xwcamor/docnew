<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El nombre del producto vive en la tabla `settings`, y el seeder no pisa
 * valores existentes: en una base ya sembrada seguia diciendo el nombre
 * heredado. Esto lo corrige sin que nadie tenga que ejecutar SQL a mano.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'app.name')
            ->whereIn('value', ['TrafoDex', 'TR APP', 'Tr Health', 'Base App', 'Laravel'])
            ->update(['value' => 'DOCUFIZ', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // No se revierte: volver al nombre anterior no aporta nada.
    }
};
