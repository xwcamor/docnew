<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Transformer;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Evita fechas de muestra repetidas en una prueba de un mismo transformador.
 *
 * Cubre el alta individual, la edición (excluye la propia fila) y el guardado
 * por lote (también detecta repetidos DENTRO del mismo request). En pruebas
 * donde la fecha sola no identifica la muestra (fpot: misma fecha a distinta
 * temperatura es válido) se pasan columnas extra en $extraKeys.
 */
trait ValidatesSampleDate
{
    /** Tope de filas por guardado en lote (anti copy-paste masivo). */
    public const MAX_BATCH_ROWS = 500;

    /**
     * @param  string    $relation   relación del Transformer (chromatographicals, fiquis, furanos, fpots)
     * @param  array     $rows       filas a validar; cada una con 'sample_date' (+ extras) y opcional 'id'
     * @param  string[]  $extraKeys  columnas que también forman parte de la unicidad (ej. ['temperature'])
     * @param  callable  $keyFor     dado el índice de fila, devuelve la clave del error (para resaltar la fila)
     */
    protected function assertUniqueSampleDates(
        Transformer $transformer,
        string $relation,
        array $rows,
        array $extraKeys = [],
        ?callable $keyFor = null,
    ): void {
        $keyFor ??= fn ($i) => 'sample_date';
        $seen = [];

        foreach ($rows as $i => $row) {
            $raw = $row['sample_date'] ?? null;
            if (! $raw) {
                continue;
            }
            $date = Carbon::parse($raw)->toDateString();

            // Firma para detectar repetidos dentro del mismo request.
            $sig = $date;
            foreach ($extraKeys as $k) {
                $sig .= '|' . (string) ($row[$k] ?? '');
            }
            if (isset($seen[$sig])) {
                $this->failDuplicate($keyFor($i), $date);
            }
            $seen[$sig] = true;

            // Repetido contra la base (excluyendo la propia fila si está editando).
            $q = $transformer->{$relation}()->whereDate('sample_date', $date);
            foreach ($extraKeys as $k) {
                $val = $row[$k] ?? null;
                $val === null ? $q->whereNull($k) : $q->where($k, $val);
            }
            if (! empty($row['id'])) {
                $q->where('id', '!=', $row['id']);
            }
            if ($q->exists()) {
                $this->failDuplicate($keyFor($i), $date);
            }
        }
    }

    private function failDuplicate(string $key, string $date): void
    {
        throw ValidationException::withMessages([
            $key => __('diagnostics.duplicate_date', ['date' => $date]),
        ]);
    }
}
