<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Concerns\ValidatesSampleDate;
use App\Http\Controllers\Controller;
use App\Models\Fiqui;
use App\Models\Transformer;
use App\Services\Diagnostics\FiquisDiagnosisService;
use App\Services\Diagnostics\HealthIndexService;
use App\Support\Transformers\TransformerShowPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * FiquiController — ensayos fisicoquímicos del aceite de un transformador.
 *
 * CRUD desde la pestaña "Fisicoquímico" del detalle. Al guardar se diagnostica la
 * muestra (score/condición/rating según aceite + clase de tensión) y se recalcula
 * el Índice de Salud. Redirige al "ver".
 */
class FiquiController extends Controller
{
    use ValidatesSampleDate;

        public function store(Request $request, Transformer $transformer, FiquisDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->assertUniqueSampleDates($transformer, 'fiquis', [$data]);

        $sample = new Fiqui();
        $sample->forceFill(array_merge($data, [
            'transformer_id' => $transformer->id,
            'created_by'     => auth()->id(),
        ]));
        $this->diagnoseAndSave($sample, $transformer, $svc);
        $hi->evaluate($transformer);

        return $this->back($transformer, __('fiquis.created'));
    }

    public function update(Request $request, Transformer $transformer, int $fiqui, FiquisDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $sample = $transformer->fiquis()->findOrFail($fiqui);
        $data = $this->validateData($request);
        $this->assertUniqueSampleDates($transformer, 'fiquis', [$data + ['id' => $sample->id]]);
        $sample->forceFill($data);
        $this->diagnoseAndSave($sample, $transformer, $svc);
        $hi->evaluate($transformer);

        return $this->back($transformer, __('fiquis.saved'));
    }

    public function destroy(Transformer $transformer, int $fiqui, HealthIndexService $hi): RedirectResponse
    {
        $sample = $transformer->fiquis()->findOrFail($fiqui);
        $sample->delete();
        $hi->evaluate($transformer);

        return $this->back($transformer, __('fiquis.deleted'));
    }

    /**
     * Traza del diagnóstico de UNA muestra (guardada o en edición) para el drawer
     * "¿Por qué este resultado?": score 1..4 de cada parámetro contra sus umbrales,
     * suma ponderada, DGAF y semáforo. Usa el aceite + tensión del transformador.
     */
    public function explain(Request $request, Transformer $transformer, FiquisDiagnosisService $svc): JsonResponse
    {
        $transformer->loadMissing('oilType');
        // FIELDS: los métodos alternos (rigidez D877, factor de potencia a
        // 100 °C) hacen falta tanto para mostrarlos contra su límite como para
        // que sustituyan al principal cuando es el único que se midió.
        $values = [];
        foreach (Fiqui::FIELDS as $p) {
            $v = $request->input($p);
            $values[$p] = ($v === null || $v === '') ? null : (float) $v;
        }

        return response()->json([
            'fiquis' => array_merge(
                $svc->explain(
                    $transformer->oilType?->code,
                    $transformer->voltage_kv === null ? null : (float) $transformer->voltage_kv,
                    $values,
                ),
                ['oil' => $transformer->oilType?->name],
            ),
        ]);
    }

    /**
     * Guardado por lote del editor estilo Excel: upserts + deletes en una
     * transacción, diagnostica cada muestra y recalcula el HI una sola vez.
     */
    public function batch(Request $request, Transformer $transformer, FiquisDiagnosisService $svc, HealthIndexService $hi): RedirectResponse
    {
        $data = $this->validateBatch($request);
        $this->assertUniqueSampleDates($transformer, 'fiquis', $data['upserts'] ?? [], [], fn ($i) => "upserts.$i.sample_date");
        $transformer->loadMissing('oilType');

        DB::transaction(function () use ($data, $transformer, $svc) {
            if (!empty($data['deletes'])) {
                $transformer->fiquis()->whereIn('id', $data['deletes'])->get()->each(fn ($s) => $s->delete());
            }
            $fields = array_merge(['sample_date', 'report_number', 'laboratory_id'], Fiqui::FIELDS);
            foreach ($data['upserts'] ?? [] as $row) {
                $id = $row['id'] ?? null;
                $sample = $id ? $transformer->fiquis()->findOrFail($id) : new Fiqui();
                if (!$sample->exists) {
                    $sample->transformer_id = $transformer->id;
                    $sample->created_by     = auth()->id();
                }
                $sample->forceFill(Arr::only($row, $fields));
                $this->diagnoseAndSave($sample, $transformer, $svc);
            }
        });

        $hi->evaluate($transformer);

        return $this->back($transformer, __('fiquis.saved'));
    }

    /** Preview en vivo: diagnostica filas en memoria (sin persistir). JSON. */
    public function preview(Request $request, Transformer $transformer, FiquisDiagnosisService $svc): JsonResponse
    {
        $transformer->loadMissing('oilType');
        $oil     = $transformer->oilType?->code;
        $voltage = $transformer->voltage_kv === null ? null : (float) $transformer->voltage_kv;

        $out = [];
        foreach ((array) $request->input('rows', []) as $i => $row) {
            $values = [];
            foreach (Fiqui::FIELDS as $p) {
                $v = $row[$p] ?? null;
                $values[$p] = ($v === null || $v === '') ? null : (float) $v;
            }
            $r = $svc->evaluate($oil, $voltage, $values);
            $out[] = [
                'key'       => $row['key'] ?? $i,
                'score'     => $r['score'] ?? null,
                'condition' => $r['condition'] ?? null,
                'color'     => $r['color'] ?? null,
            ];
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
            'deletes'               => ['array'],
            'deletes.*'             => ['integer'],
        ];
        foreach (Fiqui::FIELDS as $p) {
            $rules["upserts.*.$p"] = array_merge(
                [$this->presenceRule($p)],
                $this->rangeRules($p),
            );
        }
        return $request->validate($rules, [
            'upserts.max' => __('diagnostics.batch_too_many', ['max' => self::MAX_BATCH_ROWS]),
        ]);
    }

    private function validateData(Request $request): array
    {
        $rules = ['sample_date' => ['required', 'date'], 'report_number' => ['nullable', 'string', 'max:255'], 'laboratory_id' => ['nullable', 'integer', 'exists:laboratories,id']];
        foreach (Fiqui::FIELDS as $p) {
            $rules[$p] = array_merge([$this->presenceRule($p)], $this->rangeRules($p));
        }
        return $request->validate($rules);
    }

    /**
     * Obligatoriedad de un parámetro fisicoquímico.
     *
     * Rigidez y factor de potencia son OPCIONALES por los dos métodos. Se miden
     * con D1816 o D877, y a 25 o a 100 °C, y con cualquiera de los dos alcanza
     * porque el motor sustituye; pero también hay ensayos donde el laboratorio
     * no corrió ninguno de los dos (472 sin factor y 51 sin rigidez, solo en los
     * datos históricos). Exigir uno cualquiera obligaría a inventar una medición
     * que nunca se hizo — y eso es exactamente lo que llenó de ceros la base del
     * sistema viejo. Ausente no penaliza: el peso dinámico deja la propiedad
     * fuera del promedio.
     *
     * Los otros tres (tensión interfacial, número ácido, agua) sí se exigen: no
     * tienen método alterno y el ensayo siempre los mide.
     */
    private function presenceRule(string $param): string
    {
        return $this->esOpcional($param) ? 'nullable' : 'required';
    }

    private function esOpcional(string $param): bool
    {
        return isset(Fiqui::ALTERNATE[$param]) || in_array($param, Fiqui::EXTRA, true);
    }

    /**
     * Rango válido de un parámetro.
     *
     * En rigidez y factor de potencia el 0 se rechaza: una rigidez de 0 kV es
     * físicamente imposible y un factor de potencia de exactamente 0.000 % no es
     * medible. Ese 0 siempre significó "no medido" — es el que llenó la base del
     * sistema viejo y hacía que el motor marcara fuera de norma a
     * transformadores sanos. Ahora que los cuatro campos son opcionales, la
     * celda se deja vacía y listo.
     *
     * En acidez, agua y tensión interfacial el 0 SÍ puede ser una medición real,
     * así que ahí se acepta.
     */
    private function rangeRules(string $param): array
    {
        return ['numeric', $this->esOpcional($param) ? 'gt:0' : 'min:0', 'max:9999999'];
    }

    /** Diagnostica (score/condición/rating) según aceite + tensión y cachea en la fila. */
    private function diagnoseAndSave(Fiqui $sample, Transformer $transformer, FiquisDiagnosisService $svc): void
    {
        $values = [];
        // FIELDS, no PARAMS: los métodos alternos (D877 / factor a 100 °C)
        // sustituyen al principal cuando es el único que midió el laboratorio.
        foreach (Fiqui::FIELDS as $p) {
            $values[$p] = $sample->{$p} === null ? null : (float) $sample->{$p};
        }
        $r = $svc->evaluate(
            $transformer->oilType?->code,
            $transformer->voltage_kv === null ? null : (float) $transformer->voltage_kv,
            $values
        );
        $sample->score     = $r['score'] ?? null;
        $sample->rating    = $r['rating'] ?? null;
        $sample->condition = $r['condition'] ?? null;
        $sample->save();
    }

    private function back(Transformer $transformer, string $message): RedirectResponse
    {
        return redirect()
            ->route('business_management.transformers.show', $transformer->slug)
            ->with('success', $message);
    }
}
