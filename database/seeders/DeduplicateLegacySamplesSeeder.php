<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DeduplicateLegacySamplesSeeder — limpia las muestras duplicadas que venían en
 * el dump del sistema viejo. El viejo cargó la misma muestra varias veces
 * (artefacto de zona horaria UTC-5 + recargas del operador): mismo
 * transformer_id + misma FECHA, a horas distintas (00:00 vs 05:00…), a veces
 * con valores idénticos y a veces editados.
 *
 * Conserva UNA fila por (transformer_id, fecha), eligiendo la "mejor" por:
 *   1. no borrada (deleted_at NULL) antes que borrada,
 *   2. MÁS mediciones reales (campos con valor != null y != 0 — el 0 en el
 *      viejo significa "no medido", no es un dato),
 *   3. la editada más recientemente (updated_at del dump),
 *   4. id más alto (desempate final).
 *
 * Hard-delete: son copias redundantes, no registros que el usuario borró. El
 * SQL crudo (`*_legacy.sql`) queda INTACTO como archivo histórico — esto solo
 * limpia la BD al cargar, así que nada se pierde de forma irreversible.
 */
class DeduplicateLegacySamplesSeeder extends Seeder
{
    /** Tabla → columnas de medición (para contar cuán completa es cada fila). */
    private const TABLES = [
        'chromatographicals' => ['h2', 'o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2'],
        'fiquis'             => ['rig', 'ten', 'acid', 'wat', 'pot', 'rig877', 'pot100'],
        'furanos'            => ['fal', 'hme', 'ace', 'mfu', 'fua'],
    ];

    public function run(): void
    {
        foreach (self::TABLES as $table => $cols) {
            $removed = $this->dedupeTable($table, $cols);
            $this->command?->info("DeduplicateLegacySamplesSeeder: {$table} → {$removed} copias redundantes eliminadas.");
        }
    }

    private function dedupeTable(string $table, array $cols): int
    {
        $groups = DB::table($table)
            ->selectRaw('transformer_id, date(sample_date) as d')
            ->groupBy('transformer_id', DB::raw('date(sample_date)'))
            ->havingRaw('count(*) > 1')
            ->get();

        $removed = 0;
        foreach ($groups as $g) {
            $rows = DB::table($table)
                ->where('transformer_id', $g->transformer_id)
                ->whereRaw('date(sample_date) = ?', [$g->d])
                ->get();

            $best = null;
            $bestKey = null;
            foreach ($rows as $r) {
                $realValues = 0;
                foreach ($cols as $c) {
                    if ($r->$c !== null && (float) $r->$c != 0.0) {
                        $realValues++;
                    }
                }
                // Clave de ranking (PHP compara arrays elemento a elemento).
                $key = [
                    $r->deleted_at === null ? 1 : 0,
                    $realValues,
                    (string) $r->updated_at,
                    (int) $r->id,
                ];
                if ($best === null || $key > $bestKey) {
                    $best = $r;
                    $bestKey = $key;
                }
            }

            $ids = $rows->pluck('id')->filter(fn ($id) => (int) $id !== (int) $best->id)->all();
            if ($ids) {
                DB::table($table)->whereIn('id', $ids)->delete();
                $removed += count($ids);
            }
        }

        return $removed;
    }
}
