<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Mapa de modelos dependientes para warnings antes de eliminar.
 * Cada modelo override `dependents()`:
 *
 *   return ['countries' => [
 *       'model' => Country::class, 'fk' => 'region_id',
 *       'label' => 'countries', 'block' => false,
 *   ]];
 */
trait HasDependents
{
    public function dependents(): array
    {
        return [];
    }

    /**
     * Cuenta vivos (no soft-deleted) por relación. 1 SELECT total via
     * UNION ALL (no N por dep type). Las tablas y FKs vienen de la
     * definición estática del modelo, no de input de usuario — safe contra
     * inyección.
     */
    public function countDependents(): array
    {
        $deps = $this->dependents();
        if (empty($deps)) return [];

        $unionParts = [];
        $bindings   = [];

        foreach ($deps as $key => $cfg) {
            $modelClass = $cfg['model'];
            $fk         = $cfg['fk'];
            $table      = $this->getDependentsTable($modelClass);
            $softDelete = method_exists($modelClass, 'bootSoftDeletes');

            $sql = "SELECT ? AS dep_key, COUNT(*) AS cnt FROM {$table} WHERE {$fk} = ?";
            if ($softDelete) {
                $sql .= " AND deleted_at IS NULL";
            }

            $unionParts[] = $sql;
            $bindings[]   = $key;
            $bindings[]   = $this->getKey();
        }

        $rows = DB::select(implode(' UNION ALL ', $unionParts), $bindings);

        $out = [];
        foreach ($rows as $row) {
            if ((int) $row->cnt > 0) {
                $cfg = $deps[$row->dep_key];
                $out[$row->dep_key] = [
                    'count' => (int) $row->cnt,
                    'label' => $cfg['label'] ?? $row->dep_key,
                    'block' => (bool) ($cfg['block'] ?? false),
                ];
            }
        }

        return $out;
    }

    public function hasBlockingDependents(): bool
    {
        foreach ($this->countDependents() as $info) {
            if ($info['block']) return true;
        }
        return false;
    }

    public function totalDependentsCount(): int
    {
        $total = 0;
        foreach ($this->countDependents() as $info) {
            $total += $info['count'];
        }
        return $total;
    }

    protected function getDependentsTable(string $modelClass): string
    {
        try {
            return (new $modelClass)->getTable();
        } catch (\Throwable $e) {
            return strtolower(class_basename($modelClass)) . 's';
        }
    }
}
