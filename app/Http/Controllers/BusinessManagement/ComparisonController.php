<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\Transformer;
use App\Services\Diagnostics\RatioMethodsService;
use Illuminate\Http\Request;

/**
 * ComparisonController — "Por grupos": compara la cromatografía de VARIOS
 * transformadores a la vez. Dos páginas independientes (sin query `mode`):
 *   - gases()    → 9 gráficos (una línea por transformador).
 *   - patrones() → matriz diagnóstica comparativa.
 *
 * Solo entran transformadores CON pruebas de cromatografía (sin muestras no hay
 * nada que comparar). Scope-safe: `Transformer::query()` aplica los global scopes
 * (tenant + clientes asignados); super hace bypass.
 */
class ComparisonController extends Controller
{
    private const GASES = ['h2', 'o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2'];

    public function __construct(private RatioMethodsService $ratios)
    {
    }

    public function gases(Request $request)
    {
        return $this->render($request, 'gases');
    }

    public function patrones(Request $request)
    {
        return $this->render($request, 'patrones');
    }

    /** Label de la serie: el serial. Sin serial → tag, y sin nada → #id. */
    private static function label($t): string
    {
        return $t->serial ?: ($t->tag ?: ('#' . $t->id));
    }

    private function render(Request $request, string $mode)
    {
        // Gases (gráficos de tendencia) necesita ≥2 muestras para dibujar línea;
        // Patrones (matriz, último diagnóstico) alcanza con 1. Como TODAS las
        // opciones y filtros derivan de esta lista, quedan filtrados con la misma
        // condición automáticamente.
        $min = $mode === 'gases' ? 2 : 1;
        $txAll = Transformer::query()
            ->has('chromatographicals', '>=', $min)
            ->with(['customer:id,name', 'substation:id,name', 'brand:id,name', 'transformerType:id,name'])
            ->orderBy('serial')
            ->get(['id', 'tag', 'serial', 'customer_id', 'customer_substation_id', 'brand_id', 'transformer_type_id', 'fault_type', 'health_rating']);

        $band = fn ($r) => $r === null ? 'none' : ($r >= 3 ? 'good' : ($r === 2 ? 'regular' : 'bad'));

        $options = $txAll->map(fn ($t) => [
            'value'         => $t->id,
            'label'         => self::label($t),
            'customer_id'   => $t->customer_id,
            'substation_id' => $t->customer_substation_id,
            'brand_id'      => $t->brand_id,
            'type_id'       => $t->transformer_type_id,
            'fault'         => $t->fault_type,
            'band'          => $band($t->health_rating),
        ])->values()->all();

        // Solo dimensiones que tienen trafos (con pruebas) visibles.
        $optsFrom = fn ($rel, $fk) => $txAll->filter(fn ($t) => $t->{$rel})
            ->groupBy($fk)
            ->map(fn ($g) => ['value' => $g->first()->{$fk}, 'label' => mb_strtoupper($g->first()->{$rel}->name)])
            ->sortBy('label')->values()->all();

        $customerOptions = $optsFrom('customer', 'customer_id');
        $brandOptions    = $optsFrom('brand', 'brand_id');
        $typeOptions     = $txAll->filter(fn ($t) => $t->transformerType)
            ->groupBy('transformer_type_id')
            ->map(fn ($g) => ['value' => $g->first()->transformer_type_id, 'label' => $g->first()->transformerType->name])
            ->sortBy('label')->values()->all();

        $substationOptions = $txAll->filter(fn ($t) => $t->substation)
            ->groupBy('customer_substation_id')
            ->map(fn ($g) => [
                'value'       => $g->first()->customer_substation_id,
                'label'       => $g->first()->substation->name,
                'customer_id' => $g->first()->customer_id,
            ])
            ->sortBy('label')->values()->all();

        // Cap: gases (gráficos de línea) hasta 10; patrones (matriz) escala más.
        $cap = $mode === 'patrones' ? 40 : 10;

        // IDs seleccionados; o la flota de un cliente/subestación (deep-link).
        $ids = collect((array) $request->input('ids', []))
            ->map(fn ($v) => (int) $v)->filter()->unique();
        if ($ids->isEmpty() && $request->filled('customer')) {
            $ids = $txAll->where('customer_id', (int) $request->input('customer'))->pluck('id');
        }
        if ($ids->isEmpty() && $request->filled('substation')) {
            $ids = $txAll->where('customer_substation_id', (int) $request->input('substation'))->pluck('id');
        }
        $ids = $ids->unique()->take($cap)->values();

        $data = [];
        $matrix = [];
        if ($ids->isNotEmpty()) {
            $txs = Transformer::query()
                ->whereIn('id', $ids)
                ->with(['chromatographicals' => fn ($q) => $q->orderBy('sample_date')])
                ->get(['id', 'tag', 'serial', 'health_index', 'health_rating', 'fault_type', 'ieee_condition', 'gassing_rate']);

            if ($mode === 'patrones') {
                $matrix = $txs->map(function ($t) {
                    $last = $t->chromatographicals->last();
                    $r = $last ? $this->ratios->evaluate($last) : null;
                    $verdict = fn ($m) => ($r && ($r[$m]['complete'] ?? false)) ? $r[$m]['fault'] : null;
                    return [
                        'id'          => $t->id,
                        'label'       => self::label($t),
                        'hi_index'    => $t->health_index === null ? null : (float) $t->health_index,
                        'hi_rating'   => $t->health_rating,
                        'duval'       => $t->fault_type,
                        'ieee'        => $t->ieee_condition,
                        'rogers'      => $verdict('rogers'),
                        'doernenburg' => $verdict('doernenburg'),
                        'gassing'     => $t->gassing_rate === null ? null : (float) $t->gassing_rate,
                        'samples'     => $t->chromatographicals->count(),
                    ];
                })->values()->all();
            } else {
                $data = $txs->map(fn ($t) => [
                    'id'      => $t->id,
                    'label'   => self::label($t),
                    'samples' => $t->chromatographicals->map(function ($c) {
                        $row = ['sample_date' => optional($c->sample_date)->format('Y-m-d')];
                        foreach (self::GASES as $g) {
                            $row[$g] = $c->{$g} === null ? null : (float) $c->{$g};
                        }
                        return $row;
                    })->values()->all(),
                ])->values()->all();
            }
        }

        return inertia('Comparison/Index', [
            'mode'               => $mode,
            'cap'                => $cap,
            'transformerOptions' => $options,
            'customerOptions'    => $customerOptions,
            'substationOptions'  => $substationOptions,
            'brandOptions'       => $brandOptions,
            'typeOptions'        => $typeOptions,
            'selected'           => $ids->all(),
            'data'               => $data,
            'matrix'             => $matrix,
        ]);
    }
}
