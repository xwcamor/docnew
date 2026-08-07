<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Concerns\ValidatesSampleDate;
use App\Http\Controllers\Controller;
use App\Models\Furano;
use App\Models\Transformer;
use App\Services\Diagnostics\FuranoDiagnosisService;
use App\Services\Diagnostics\HealthIndexService;
use App\Support\Transformers\TransformerShowPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * FuranoController — ensayos de furanos (degradación del papel) de un transformador.
 *
 * CRUD de muestras desde la pestaña "Furanos" del detalle del trafo. Al guardar,
 * se diagnostica la muestra (condición + rating por 2-FAL y DP de Chendong, cacheados
 * en la fila) y se recalcula el Índice de Salud. Todo redirige al "ver".
 */
class FuranoController extends Controller
{
    /** Compuestos furánicos medidos (ppb). `fal` es el que diagnostica. */
    private const COMPOUNDS = ['fal', 'hme', 'ace', 'mfu', 'fua'];

    use ValidatesSampleDate;

        public function store(Request $request, Transformer $transformer, FuranoDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->assertUniqueSampleDates($transformer, 'furanos', [$data]);

        $sample = new Furano();
        $sample->forceFill(array_merge($data, [
            'transformer_id' => $transformer->id,
            'created_by'     => auth()->id(),
        ]));
        $this->diagnoseAndSave($sample, $svc);
        $hi->evaluate($transformer);

        return $this->back($transformer, __('furanos.created'));
    }

    public function update(Request $request, Transformer $transformer, int $furano, FuranoDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $sample = $transformer->furanos()->findOrFail($furano);
        $data = $this->validateData($request);
        $this->assertUniqueSampleDates($transformer, 'furanos', [$data + ['id' => $sample->id]]);
        $sample->forceFill($data);
        $this->diagnoseAndSave($sample, $svc);
        $hi->evaluate($transformer);

        return $this->back($transformer, __('furanos.saved'));
    }

    public function destroy(Transformer $transformer, int $furano, HealthIndexService $hi): RedirectResponse
    {
        $sample = $transformer->furanos()->findOrFail($furano);
        $sample->delete();
        $hi->evaluate($transformer);

        return $this->back($transformer, __('furanos.deleted'));
    }

    /**
     * Traza del diagnóstico de UNA muestra (guardada o en edición) para el drawer
     * "¿Por qué este resultado?": conversión fal→ppm, DP de Chendong y la escala.
     */
    public function explain(Request $request, Transformer $transformer, FuranoDiagnosisService $svc): JsonResponse
    {
        $fal = $request->input('fal');
        return response()->json([
            'furanos' => $svc->explain(
                $fal === null || $fal === '' ? null : (float) $fal,
                $transformer->paper_type,
            ),
        ]);
    }

    /**
     * Guardado por lote del editor estilo Excel: upserts + deletes en una
     * transacción, diagnostica cada muestra y recalcula el HI una sola vez.
     */
    public function batch(Request $request, Transformer $transformer, FuranoDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $data = $this->validateBatch($request);
        $this->assertUniqueSampleDates($transformer, 'furanos', $data['upserts'] ?? [], [], fn ($i) => "upserts.$i.sample_date");

        DB::transaction(function () use ($data, $transformer, $svc) {
            if (!empty($data['deletes'])) {
                $transformer->furanos()->whereIn('id', $data['deletes'])->get()->each(fn ($s) => $s->delete());
            }
            $fields = array_merge(['sample_date', 'report_number', 'laboratory_id'], self::COMPOUNDS);
            foreach ($data['upserts'] ?? [] as $row) {
                $id = $row['id'] ?? null;
                $sample = $id ? $transformer->furanos()->findOrFail($id) : new Furano();
                if (!$sample->exists) {
                    $sample->transformer_id = $transformer->id;
                    $sample->created_by     = auth()->id();
                }
                $sample->forceFill(Arr::only($row, $fields));
                $this->diagnoseAndSave($sample, $svc);
            }
        });

        $hi->evaluate($transformer);

        return $this->back($transformer, __('furanos.saved'));
    }

    /** Preview en vivo: diagnostica filas en memoria (sin persistir). JSON. */
    public function preview(Request $request, Transformer $transformer, FuranoDiagnosisService $svc): JsonResponse
    {
        $out = [];
        foreach ((array) $request->input('rows', []) as $i => $row) {
            $fal = $row['fal'] ?? null;
            $r = $svc->evaluate($fal === null || $fal === '' ? null : (float) $fal);
            $out[] = ['key' => $row['key'] ?? $i, 'dp' => $r['dp'], 'condition' => $r['condition'], 'color' => $r['color']];
        }
        return response()->json(['rows' => $out]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    private function validateBatch(Request $request): array
    {
        $rules = [
            'upserts'               => ['array', 'max:' . self::MAX_BATCH_ROWS],
            'upserts.*.id'          => ['nullable', 'integer'],
            'upserts.*.sample_date' => ['required', 'date'],
            'upserts.*.report_number' => ['nullable', 'string', 'max:255'],
            'upserts.*.laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id'],
            'upserts.*.fal'         => ['required', 'numeric', 'min:0', 'max:99999999'],
            'deletes'               => ['array'],
            'deletes.*'             => ['integer'],
        ];
        foreach (['hme', 'ace', 'mfu', 'fua'] as $c) {
            $rules["upserts.*.$c"] = ['nullable', 'numeric', 'min:0', 'max:99999999'];
        }
        return $request->validate($rules, [
            'upserts.max' => __('diagnostics.batch_too_many', ['max' => self::MAX_BATCH_ROWS]),
        ]);
    }

    private function validateData(Request $request): array
    {
        $rules = [
            'sample_date' => ['required', 'date'],
            'report_number' => ['nullable', 'string', 'max:255'],
            'laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id'],
            'fal'         => ['required', 'numeric', 'min:0', 'max:99999999'],
        ];
        foreach (['hme', 'ace', 'mfu', 'fua'] as $c) {
            $rules[$c] = ['nullable', 'numeric', 'min:0', 'max:99999999'];
        }
        return $request->validate($rules);
    }

    /** Diagnostica (condición + rating + DP) y cachea en la fila. */
    private function diagnoseAndSave(Furano $sample, FuranoDiagnosisService $svc): void
    {
        $r = $svc->evaluate($sample->fal === null ? null : (float) $sample->fal);
        $sample->dp        = $r['dp'];
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
