<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Segunda red contra muestras duplicadas: el mismo informe de laboratorio no
 * puede entrar dos veces en la misma prueba del mismo workspace.
 *
 * La primera red es la clave de idempotencia (cubre el reintento de UNA
 * petición). Ésta cubre el caso distinto: dos peticiones legítimamente
 * diferentes —claves distintas— que traen el MISMO informe, típico cuando se
 * reprocesa una cola vieja o alguien reenvía a mano desde la bandeja.
 *
 * El índice es PARCIAL a propósito:
 *
 *   - `report_number IS NOT NULL`: hay ~20.000 muestras históricas (migradas
 *     del sistema Ruby, que no guardaba el número de informe) con la columna en
 *     null. Un índice único común las dejaría pasar igual en Postgres —dos NULL
 *     son distintos— pero el WHERE lo deja explícito y evita indexar 20.000
 *     filas que nunca se van a consultar por ahí.
 *   - `deleted_at IS NULL`: una muestra borrada no debe bloquear la recarga del
 *     mismo informe. Sin esto, borrar y reenviar daría error de clave duplicada.
 *
 * `laboratory_id` entra en la clave porque el número de informe lo correlativa
 * cada laboratorio: dos laboratorios pueden emitir su "REP-2026-0001".
 *
 * Si la base ya tuviera duplicados cargados a mano, la migración NO explota:
 * avisa y salta esa tabla. Preferimos un deploy que termina y un aviso a leer,
 * antes que dejar el sistema a medio migrar por datos viejos.
 */
return new class extends Migration {
    /** tabla => nombre del índice (Postgres corta en 63 caracteres). */
    private array $tables = [
        'chromatographicals' => 'chromato_report_number_unique',
        'fiquis'             => 'fiquis_report_number_unique',
        'furanos'            => 'furanos_report_number_unique',
        'fpots'              => 'fpots_report_number_unique',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $index) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $dupes = DB::table($table)
                ->select('tenant_id', 'laboratory_id', 'report_number', DB::raw('COUNT(*) as total'))
                ->whereNotNull('report_number')
                ->whereNull('deleted_at')
                ->groupBy('tenant_id', 'laboratory_id', 'report_number')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($dupes > 0) {
                echo "  [saltado] {$table}: {$dupes} informes repetidos ya cargados. "
                   . "Resuélvalos y vuelva a correr esta migración.\n";
                continue;
            }

            DB::statement(
                "CREATE UNIQUE INDEX {$index} ON {$table} (tenant_id, laboratory_id, report_number) "
                . 'WHERE report_number IS NOT NULL AND deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
