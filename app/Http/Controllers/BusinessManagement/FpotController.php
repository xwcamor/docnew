<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Concerns\ValidatesSampleDate;
use App\Http\Controllers\Controller;
use App\Models\Fpot;
use App\Models\Transformer;
use App\Services\Diagnostics\FpotDiagnosisService;
use App\Services\Diagnostics\HealthIndexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * FpotController — ensayos de Factor de Potencia del aislamiento de un transformador.
 *
 * CRUD de muestras desde la pestaña "Factor de Potencia" del detalle del trafo. Al
 * guardar, se diagnostica la muestra (condición + rating por la escala única de fpot,
 * cacheados en la fila) y se recalcula el Índice de Salud. Todo redirige al "ver".
 */
class FpotController extends Controller
{
    use ValidatesSampleDate;

    public function store(Request $request, Transformer $transformer, FpotDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->assertUniqueSampleDates($transformer, 'fpots', [$data], ['temperature']);

        $sample = new Fpot();
        $sample->forceFill(array_merge($data, [
            'transformer_id' => $transformer->id,
            'created_by'     => auth()->id(),
        ]));
        $this->diagnoseAndSave($sample, $svc);
        $hi->evaluate($transformer);

        return $this->back($transformer, __('fpot.created'));
    }

    public function update(Request $request, Transformer $transformer, int $fpot, FpotDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $sample = $transformer->fpots()->findOrFail($fpot);
        $data = $this->validateData($request);
        $this->assertUniqueSampleDates($transformer, 'fpots', [$data + ['id' => $sample->id]], ['temperature']);
        $sample->forceFill($data);
        $this->diagnoseAndSave($sample, $svc);
        $hi->evaluate($transformer);

        return $this->back($transformer, __('fpot.saved'));
    }

    public function destroy(Transformer $transformer, int $fpot, HealthIndexService $hi): RedirectResponse
    {
        $sample = $transformer->fpots()->findOrFail($fpot);
        $sample->delete();
        $hi->evaluate($transformer);

        return $this->back($transformer, __('fpot.deleted'));
    }

    /**
     * Guardado por lote del editor estilo Excel: upserts + deletes en una
     * transacción, diagnostica cada muestra y recalcula el HI una sola vez.
     */
    public function batch(Request $request, Transformer $transformer, FpotDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $data = $this->validateBatch($request);
        $this->assertUniqueSampleDates($transformer, 'fpots', $data['upserts'] ?? [], ['temperature'], fn ($i) => "upserts.$i.sample_date");

        DB::transaction(function () use ($data, $transformer, $svc) {
            if (!empty($data['deletes'])) {
                $transformer->fpots()->whereIn('id', $data['deletes'])->get()->each(fn ($s) => $s->delete());
            }
            foreach ($data['upserts'] ?? [] as $row) {
                $id = $row['id'] ?? null;
                $sample = $id ? $transformer->fpots()->findOrFail($id) : new Fpot();
                if (!$sample->exists) {
                    $sample->transformer_id = $transformer->id;
                    $sample->created_by     = auth()->id();
                }
                $sample->forceFill(Arr::only($row, ['sample_date', 'report_number', 'laboratory_id', 'value', 'temperature']));
                $this->diagnoseAndSave($sample, $svc);
            }
        });

        $hi->evaluate($transformer);

        return $this->back($transformer, __('fpot.saved'));
    }

    /** Preview en vivo: diagnostica filas en memoria (sin persistir). JSON. */
    public function preview(Request $request, Transformer $transformer, FpotDiagnosisService $svc): JsonResponse
    {
        $out = [];
        foreach ((array) $request->input('rows', []) as $i => $row) {
            $v = $row['value'] ?? null;
            $r = $svc->evaluate($v === null || $v === '' ? null : (float) $v);
            $out[] = ['key' => $row['key'] ?? $i, 'condition' => $r['condition'], 'color' => $r['color'], 'rating' => $r['rating']];
        }
        return response()->json(['rows' => $out]);
    }

    /** Traza del diagnóstico de UNA muestra para el drawer "¿Por qué este resultado?". */
    public function explain(Request $request, Transformer $transformer, FpotDiagnosisService $svc): JsonResponse
    {
        $v = $request->input('value');
        return response()->json([
            'fpot' => $svc->explain($v === null || $v === '' ? null : (float) $v),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function validateBatch(Request $request): array
    {
        return $request->validate([
            'upserts'                 => ['array', 'max:' . self::MAX_BATCH_ROWS],
            'upserts.*.id'            => ['nullable', 'integer'],
            'upserts.*.sample_date'   => ['required', 'date'],
            'upserts.*.report_number' => ['nullable', 'string', 'max:255'],
            'upserts.*.laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id'],
            'upserts.*.value'         => ['required', 'numeric', 'min:0', 'max:999999'],
            'upserts.*.temperature'   => ['required', 'numeric'],
            'deletes'                 => ['array'],
            'deletes.*'               => ['integer'],
        ], [
            'upserts.max' => __('diagnostics.batch_too_many', ['max' => self::MAX_BATCH_ROWS]),
        ]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'sample_date' => ['required', 'date'],
            'report_number' => ['nullable', 'string', 'max:255'],
            'laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id'],
            'value'       => ['required', 'numeric', 'min:0', 'max:999999'],
            'temperature' => ['required', 'numeric'],
        ]);
    }

    /** Diagnostica (condición + rating) y cachea en la fila. */
    private function diagnoseAndSave(Fpot $sample, FpotDiagnosisService $svc): void
    {
        $r = $svc->evaluate($sample->value === null ? null : (float) $sample->value);
        $sample->rating    = $r['rating'];
        $sample->condition = $r['condition'];
        $sample->save();
    }

    private function back(Transformer $transformer, string $message): RedirectResponse
    {
        return redirect()
            ->route('business_management.transformers.show', $transformer->slug)
            ->with('success', $message);
    }
}
