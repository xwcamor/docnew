<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Habilita `unaccent` y crea el wrapper IMMUTABLE necesario para indexes.
 * Solo Postgres — no-op en MySQL/SQLite.
 *
 * `unaccent_immutable(text)` es lo que referencian los unique partials de
 * regions/languages/countries/locales/tenants/etc. Postgres marca `unaccent()`
 * base como STABLE (no IMMUTABLE) — no indexable directo — por eso el wrapper.
 *
 * Corre PRIMERO de todas las migrations para que las tablas puedan referenciar
 * la función al momento de crear sus partial unique indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        DB::statement(
            "CREATE OR REPLACE FUNCTION unaccent_immutable(text) RETURNS text " .
            "LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS \$\$ " .
            "SELECT public.unaccent(\$1) \$\$;"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        DB::statement('DROP FUNCTION IF EXISTS unaccent_immutable(text)');
        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
};
