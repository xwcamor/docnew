<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan db:fix-sequences
 *
 * Resincroniza las secuencias (auto-increment) de Postgres con el MAX(id) real
 * de cada tabla. Necesario porque los dumps legacy se importan con IDs
 * explícitos vía SQL crudo (`*_legacy.sql`), lo que NO avanza la secuencia: el
 * siguiente INSERT del sistema (crear/duplicar) reusa un id viejo y revienta con
 * "duplicate key value violates unique constraint ..._pkey".
 *
 * Es idempotente y seguro de correr cuantas veces haga falta. Solo Postgres
 * (sqlite no usa secuencias). Conviene correrlo después de seedear datos legacy.
 */
class FixDatabaseSequencesCommand extends Command
{
    protected $signature = 'db:fix-sequences';

    protected $description = 'Resincroniza las secuencias de IDs de Postgres con el MAX(id) de cada tabla (arregla "duplicate key ..._pkey" tras importar dumps).';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('Solo aplica a Postgres; la conexión actual es ' . DB::getDriverName() . '. Nada que hacer.');
            return self::SUCCESS;
        }

        // Solo tablas que tienen columna `id`. pg_get_serial_sequence() lanza
        // un error (no devuelve NULL) si la columna no existe, así que NO se le
        // puede pasar 'id' a ciegas: tablas como cache, cache_locks, sessions o
        // password_reset_tokens no tienen `id` y harían fallar todo el comando.
        $tables = DB::select(
            "SELECT table_name AS tablename
               FROM information_schema.columns
              WHERE table_schema = 'public' AND column_name = 'id'
           ORDER BY table_name"
        );
        $fixed = 0;

        foreach ($tables as $row) {
            $table = $row->tablename;

            // Solo tablas con secuencia en la columna `id` (serial/bigserial).
            $seq = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS seq', [$table, 'id'])->seq ?? null;
            if (!$seq) {
                continue;
            }

            $max = (int) (DB::selectOne('SELECT COALESCE(MAX(id), 0) AS m FROM "' . $table . '"')->m ?? 0);

            if ($max > 0) {
                // is_called = true → el próximo nextval() devuelve $max + 1.
                DB::statement('SELECT setval(?::regclass, ?, true)', [$seq, $max]);
            } else {
                // Tabla vacía → el próximo nextval() devuelve 1.
                DB::statement('SELECT setval(?::regclass, 1, false)', [$seq]);
            }

            $this->line(sprintf('  %-32s → next id = %d', $table, $max + 1));
            $fixed++;
        }

        $this->info("Listo: {$fixed} secuencias resincronizadas.");
        return self::SUCCESS;
    }
}
