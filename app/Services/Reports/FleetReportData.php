<?php

namespace App\Services\Reports;

use App\Models\Chromatographical;
use App\Models\Fiqui;
use App\Models\Fpot;
use App\Models\Furano;
use Illuminate\Support\Collection;

/**
 * Arma el RESUMEN de flota (1 fila por transformador) que comparten el reporte
 * CSV (tabla plana) y el PDF (documento presentable): identidad + índice de
 * salud + condición por prueba (última muestra) + cachés de diagnóstico.
 *
 * La condición por prueba se toma de la última muestra de cada test (columnas
 * `*_condition`), sin re-correr los motores: rápido y suficiente para un
 * resumen textual.
 */
class FleetReportData
{
    /**
     * @param Collection $transformers ya cargados con customer + transformerType
     * @return array<int, array<string, mixed>>
     */
    public function summary(Collection $transformers): array
    {
        $ids = $transformers->pluck('id')->all();
        $cromas = $this->latestCondition(Chromatographical::class, $ids, 'dgaf_condition');
        $fur    = $this->latestCondition(Furano::class, $ids, 'condition');
        $fiq    = $this->latestCondition(Fiqui::class, $ids, 'condition');
        $fpo    = $this->latestCondition(Fpot::class, $ids, 'condition');

        return $transformers->map(fn ($t) => [
            'id'         => $t->id,
            'serial'     => $t->serial ?: '—',
            'tag'        => $t->tag ?: '—',
            'customer'   => $t->customer?->name ?? '—',
            'type'       => $t->transformerType?->name ?? '—',
            'voltage_kv' => $t->voltage_kv,
            'power_mva'  => $t->power_mva,
            'year'       => $t->manufacture_year,
            'hi'         => $t->health_index !== null ? round((float) $t->health_index, 1) : null,
            'rating'     => $t->health_rating,
            'trend'      => $t->health_trend,
            'fault_type' => $t->fault_type,
            'ieee'       => $t->ieee_condition,
            'dp'         => $t->paper_dp,
            'cromas'     => $cromas[$t->id] ?? null,
            'furanos'    => $fur[$t->id] ?? null,
            'fiquis'     => $fiq[$t->id] ?? null,
            'fpot'       => $fpo[$t->id] ?? null,
        ])->all();
    }

    /** Mapa transformer_id → condición de la ÚLTIMA muestra del test. */
    private function latestCondition(string $model, array $ids, string $field): array
    {
        if (empty($ids)) {
            return [];
        }

        return $model::withoutGlobalScopes()
            ->whereIn('transformer_id', $ids)
            ->orderBy('transformer_id')
            ->orderByDesc('sample_date')
            ->get(['transformer_id', $field, 'sample_date'])
            ->groupBy('transformer_id')
            ->map(fn ($g) => $g->first()->{$field})
            ->all();
    }
}
