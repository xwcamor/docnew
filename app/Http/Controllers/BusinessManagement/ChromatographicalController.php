<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Concerns\ValidatesSampleDate;
use App\Http\Controllers\Controller;
use App\Models\Chromatographical;
use App\Models\Transformer;
use App\Services\Diagnostics\ChromatographyEngine;
use App\Services\Diagnostics\HealthIndexService;
use App\Support\Transformers\TransformerShowPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * ChromatographicalController — ensayos de cromatografía (DGA) de un transformador.
 *
 * CRUD de muestras desde la pestaña "Análisis Cromatográfico" del detalle del
 * trafo. Al guardar/editar, el motor diagnostica la muestra (cachea score +
 * condición) y se recalcula el Índice de Salud del transformador. Todo redirige
 * de vuelta al "ver" para refrescar el listado y el dashboard.
 */
class ChromatographicalController extends Controller
{
    use ValidatesSampleDate;

        public function store(Request $request, Transformer $transformer, ChromatographyEngine $engine, HealthIndexService $hi): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->assertUniqueSampleDates($transformer, 'chromatographicals', [$data]);

        $sample = new Chromatographical();
        $sample->forceFill(array_merge($data, [
            'transformer_id' => $transformer->id,
            'created_by'     => auth()->id(),
        ]));
        $this->diagnoseAndSave($sample, $transformer, $engine);
        $hi->evaluate($transformer);

        return $this->back($transformer, __('cromas.created'));
    }

    public function update(Request $request, Transformer $transformer, int $croma, ChromatographyEngine $engine, HealthIndexService $hi): RedirectResponse
    {
        $sample = $transformer->chromatographicals()->findOrFail($croma);
        $data = $this->validateData($request);
        $this->assertUniqueSampleDates($transformer, 'chromatographicals', [$data + ['id' => $sample->id]]);

        $sample->forceFill($data);
        $this->diagnoseAndSave($sample, $transformer, $engine);
        $hi->evaluate($transformer);

        return $this->back($transformer, __('cromas.saved'));
    }

    public function destroy(Transformer $transformer, int $croma, HealthIndexService $hi): RedirectResponse
    {
        $sample = $transformer->chromatographicals()->findOrFail($croma);
        $sample->delete();
        $hi->evaluate($transformer);

        return $this->back($transformer, __('cromas.deleted'));
    }

    /**
     * Guardado por lote del editor estilo Excel. Recibe filas a crear/editar
     * (`upserts`, con `id` nulo para nuevas) y `deletes` (ids a borrar). Todo en
     * una transacción; diagnostica cada muestra y recalcula el Índice de Salud
     * UNA sola vez al final (no N veces como los endpoints individuales).
     */
    public function batch(Request $request, Transformer $transformer, ChromatographyEngine $engine, HealthIndexService $hi): RedirectResponse
    {
        $data = $this->validateBatch($request);
        $this->assertUniqueSampleDates($transformer, 'chromatographicals', $data['upserts'] ?? [], [], fn ($i) => "upserts.$i.sample_date");

        DB::transaction(function () use ($data, $transformer, $engine) {
            // Borrados primero (scoped al trafo para no tocar otros).
            if (!empty($data['deletes'])) {
                $transformer->chromatographicals()
                    ->whereIn('id', $data['deletes'])
                    ->get()
                    ->each(fn ($s) => $s->delete());
            }

            $fields = array_merge(['sample_date', 'report_number', 'laboratory_id'], Chromatographical::GASES);
            foreach ($data['upserts'] ?? [] as $row) {
                $id = $row['id'] ?? null;
                $sample = $id
                    ? $transformer->chromatographicals()->findOrFail($id)
                    : new Chromatographical();

                if (!$sample->exists) {
                    $sample->transformer_id = $transformer->id;
                    $sample->created_by     = auth()->id();
                }

                $sample->forceFill(Arr::only($row, $fields));
                $this->diagnoseAndSave($sample, $transformer, $engine);
            }
        });

        $hi->evaluate($transformer);

        return $this->back($transformer, __('cromas.saved'));
    }

    /**
     * Preview en vivo: corre el motor sobre filas en memoria (sin persistir) y
     * devuelve condición + color por fila, para que la grilla muestre el
     * semáforo mientras el usuario escribe. Devuelve JSON (no Inertia).
     */
    public function preview(Request $request, Transformer $transformer, ChromatographyEngine $engine): JsonResponse
    {
        $rows = $request->input('rows', []);
        $out  = [];

        foreach (is_array($rows) ? $rows : [] as $i => $row) {
            $sample = new Chromatographical();
            $sample->transformer_id = $transformer->id;
            $sample->sample_date    = $this->safeDate($row['sample_date'] ?? null);
            foreach (Chromatographical::GASES as $g) {
                $v = $row[$g] ?? null;
                $sample->{$g} = ($v === null || $v === '') ? null : (float) $v;
            }
            $sample->setRelation('transformer', $transformer);

            $r = $engine->evaluate($sample);
            $out[] = [
                'key'       => $row['key'] ?? $i,
                'score'     => $r->score !== null ? round($r->score, 2) : null,
                'condition' => $r->condition,
                'color'     => $r->color,
            ];
        }

        return response()->json(['rows' => $out]);
    }

    /**
     * Traza del diagnóstico de UNA fila (guardada o en edición) para el drawer
     * "¿Por qué este resultado?": cálculo del DGAF paso a paso (cromas). Corre el
     * motor sin persistir. Devuelve JSON.
     */
    public function explain(Request $request, Transformer $transformer, ChromatographyEngine $engine): JsonResponse
    {
        $row = $request->input('row', []);

        $sample = new Chromatographical();
        $sample->transformer_id = $transformer->id;
        $sample->sample_date    = $this->safeDate($row['sample_date'] ?? null);
        foreach (Chromatographical::GASES as $g) {
            $v = $row[$g] ?? null;
            $sample->{$g} = ($v === null || $v === '') ? null : (float) $v;
        }
        $sample->setRelation('transformer', $transformer);

        return response()->json([
            'cromas' => $engine->explain($sample),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Fecha defensiva para preview/explain (en vivo, sin persistir): si el valor
     * no es una fecha válida (ej. columna corrida en un pegado de Excel → queda
     * "Muy Bueno" o "2024-" en la celda), cae a hoy en vez de reventar con Carbon
     * (500). La fecha no influye en el diagnóstico, así que el cálculo es igual.
     */
    private function safeDate($value): \Illuminate\Support\Carbon
    {
        if (empty($value)) {
            return now();
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }
    }

    private function validateBatch(Request $request): array
    {
        $rules = [
            'upserts'               => ['array', 'max:' . self::MAX_BATCH_ROWS],
            'upserts.*.id'          => ['nullable', 'integer'],
            'upserts.*.sample_date' => ['required', 'date'],
            'upserts.*.report_number' => ['nullable', 'string', 'max:255'],
            'upserts.*.laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id'],
            'deletes'               => ['array'],
            'deletes.*'             => ['integer'],
        ];
        foreach (Chromatographical::GASES as $g) {
            $rules["upserts.*.$g"] = ['required', 'numeric', 'min:0', 'max:9999999'];
        }
        return $request->validate($rules, [
            'upserts.max' => __('diagnostics.batch_too_many', ['max' => self::MAX_BATCH_ROWS]),
        ]);
    }

    private function validateData(Request $request): array
    {
        $rules = ['sample_date' => ['required', 'date'], 'report_number' => ['nullable', 'string', 'max:255'], 'laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id']];
        foreach (Chromatographical::GASES as $g) {
            $rules[$g] = ['required', 'numeric', 'min:0', 'max:9999999'];
        }
        return $request->validate($rules);
    }

    /** Corre el motor sobre la muestra, cachea el resultado y guarda. */
    private function diagnoseAndSave(Chromatographical $sample, Transformer $transformer, ChromatographyEngine $engine): void
    {
        $sample->setRelation('transformer', $transformer);
        $result = $sample->exists || $sample->transformer_id
            ? $engine->evaluate($sample)
            : null;

        if ($result) {
            $sample->dgaf_score     = $result->score;
            $sample->dgaf_condition = $result->condition;
        }
        $sample->save();
    }

    private function back(Transformer $transformer, string $message): RedirectResponse
    {
        return redirect()
            ->route('business_management.transformers.show', $transformer->slug)
            ->with('success', $message);
    }
}
