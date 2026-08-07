<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renombra la app a "TR Health". El nombre comercial vive en el setting
 * `app.name` (login, header, emails, título del browser, fallback de informes).
 * Esta migración lo fija en la BD actual; el seeder y los .env quedan alineados.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->where('key', 'app.name')->update([
            'value'      => 'TR Health',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'app.name')->update([
            'value'      => 'Application Name',
            'updated_at' => now(),
        ]);
    }
};
