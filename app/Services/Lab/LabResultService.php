<?php

namespace App\Services\Lab;

use App\Http\Controllers\Concerns\ValidatesSampleDate;
use App\Models\Chromatographical;
use App\Models\Fiqui;
use App\Models\Fpot;
use App\Models\Furano;
use App\Models\Laboratory;
use App\Models\Transformer;
use App\Services\Diagnostics\ChromatographyEngine;
use App\Services\Diagnostics\FiquisDiagnosisService;
use App\Services\Diagnostics\FpotDiagnosisService;
use App\Services\Diagnostics\FuranoDiagnosisService;
use App\Services\Diagnostics\HealthIndexService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Ingesta de resultados del laboratorio.
 *
 * Traduce un envío por CÓDIGO DE ANALITO (como lo tiene el laboratorio) a las
 * cuatro tablas de muestras, diagnostica cada una con el MISMO motor que usa el
 * formulario web y recalcula el índice de salud. La equivalencia analito →
 * columna vive en `config/lab_integration.php`, no aquí: agregar un parámetro
 * tiene que ser una línea de configuración, no un `if` nuevo en el código.
 *
 * Lo que este servicio NO hace, a propósito:
 *   - No adivina el transformador (eso es LabTransformerService y falla fuerte
 *     si hay más de un candidato).
 *   - No evalúa contra criterio de aceptación: eso lo dice el informe del
 *     laboratorio. Acá se diagnostica el EQUIPO, que es otra pregunta.
 */
class LabResultService
{
    // La regla "una muestra por fecha y prueba" es la misma que aplica el
    // formulario web. Se reusa el trait en vez de reescribirla para que la API
    // y la interfaz no puedan divergir en qué consideran duplicado.
    use ValidatesSampleDate;

    public function __construct(
        private ChromatographyEngine $cromas,
        private FiquisDiagnosisService $fiquis,
        private FuranoDiagnosisService $furanos,
        private FpotDiagnosisService $fpot,
        private HealthIndexService $health,
    ) {
    }

    /**
     * Guarda los ensayos de un envío y devuelve el detalle de lo creado.
     *
     * El llamador ya abrió la transacción (el middleware de idempotencia), así
     * que aquí no se abre otra: o entran todos los ensayos del informe o no
     * entra ninguno.
     *
     * @param  array  $lab    report_number, laboratory / laboratory_code, sampled_at
     * @param  array  $tests  lista de ensayos: kind, measured_at, values, methods
     * @return array{created: array, warnings: array}
     */
    public function ingest(Transformer $transformer, array $lab, array $tests): array
    {
        $transformer->loadMissing('oilType');

        $laboratoryId = $this->resolveLaboratory($lab);
        $reportNumber = $this->str($lab['report_number'] ?? null);
        $fallbackDate = $lab['sampled_at'] ?? null;

        $created  = [];
        $warnings = [];

        foreach ($tests as $i => $test) {
            $kind   = (string) ($test['kind'] ?? '');
            $config = config("lab_integration.tests.{$kind}");

            if (! $config) {
                throw ValidationException::withMessages([
                    "tests.{$i}.kind" => __('lab_api.unknown_kind', [
                        'kind'    => $kind === '' ? '(vacío)' : $kind,
                        'allowed' => implode(', ', array_keys(config('lab_integration.tests'))),
                    ]),
                ]);
            }

            $values = $this->mapValues($kind, $config, (array) ($test['values'] ?? []), (array) ($test['methods'] ?? []), $i);
            $date   = $this->sampleDate($test['measured_at'] ?? $fallbackDate, $i);

            $row = $values + [
                'sample_date'   => $date,
                'report_number' => $reportNumber,
                'laboratory_id' => $laboratoryId,
            ];

            // La unicidad de fpot incluye la temperatura: la misma fecha a otra
            // temperatura es un ensayo legítimo distinto.
            //
            // Alcanza con mirar la fila actual contra la base: los ensayos
            // anteriores de este mismo envío ya se guardaron dentro de la
            // transacción, así que un envío con dos cromatografías de la misma
            // fecha choca igual.
            $extraKeys = $kind === 'power_factor' ? ['temperature'] : [];
            $this->assertUniqueSampleDates(
                $transformer,
                $config['relation'],
                [$row],
                $extraKeys,
                fn () => "tests.{$i}.measured_at",
            );

            [$sample, $summary, $warning] = $this->persist($kind, $config, $transformer, $row, (array) ($test['methods'] ?? []));

            $created[] = ['kind' => $kind, 'id' => $sample->id] + $summary;
            if ($warning) {
                $warnings[] = $warning;
            }
        }

        // Mismo recálculo que hace la interfaz al guardar una muestra: el índice
        // de salud y, dentro de él, la caché de flota del transformador
        // (fault_type, gassing_rate, paper_dp, ieee_condition…). Sin esto el
        // dato entraría con el diagnóstico viejo colgando, que es exactamente lo
        // que pasaba cuando el laboratorio escribía por SQL.
        //
        // El job RecalculateFleetCache NO va aquí: ese recorre la flota entera y
        // existe para cuando cambian las REGLAS. Acá cambió un transformador.
        $hi = $this->health->evaluate($transformer);

        return ['created' => $created, 'warnings' => $warnings, 'health' => $hi];
    }

    /**
     * Traduce los códigos de analito a columnas. Un código desconocido es un
     * 422, nunca un descarte silencioso: si el laboratorio midió algo y aquí se
     * pierde sin aviso, el informe del cliente sale con menos de lo que se pagó
     * y nadie se entera hasta que alguien compara los dos papeles.
     */
    private function mapValues(string $kind, array $config, array $values, array $methods, int $i): array
    {
        $out = [];

        foreach ($values as $code => $value) {
            $column = $config['values'][$code] ?? null;

            if ($column === null) {
                throw ValidationException::withMessages([
                    "tests.{$i}.values.{$code}" => __('lab_api.unknown_analyte', [
                        'kind' => $kind,
                        'code' => $code,
                    ]),
                ]);
            }

            if ($value === null || $value === '') {
                continue;   // el laboratorio declara el parámetro pero no lo midió
            }

            $target = $this->routeByMethod($code, $column, $methods);

            // Ninguna medición es negativa; la única columna donde un negativo
            // tiene sentido físico es la temperatura del ensayo.
            if ($target !== 'temperature' && (float) $value < 0) {
                throw ValidationException::withMessages([
                    "tests.{$i}.values.{$code}" => __('validation.min.numeric', ['attribute' => $code, 'min' => 0]),
                ]);
            }

            $out[$target] = (float) $value;
        }

        if (empty($out)) {
            throw ValidationException::withMessages([
                "tests.{$i}.values" => __('lab_api.no_values', ['kind' => $kind]),
            ]);
        }

        foreach ($config['required'] as $required) {
            if (! array_key_exists($required, $out)) {
                throw ValidationException::withMessages([
                    "tests.{$i}.values" => __('lab_api.missing_required', ['kind' => $kind, 'code' => $required]),
                ]);
            }
        }

        return $out;
    }

    /**
     * Elige la columna según el MÉTODO con que se midió.
     *
     * El laboratorio manda un solo código (`rig`, `pot`) y describe el método
     * aparte. Acá el método está en la columna: `rig` es D1816 y `rig877` es
     * D877; `pot` es a 25 °C y `pot100` a 100 °C. Sin esta traducción una
     * rigidez medida con D877 se guardaría como D1816 y se compararía contra un
     * umbral que no le corresponde — los kV de las dos normas no son
     * comparables.
     */
    private function routeByMethod(string $code, string $column, array $methods): string
    {
        $rules = config("lab_integration.method_routing.{$code}");
        if (! $rules || ! isset($methods[$code])) {
            return $column;
        }

        $method   = (array) $methods[$code];
        $standard = strtoupper((string) ($method['standard'] ?? ''));
        $temp     = isset($method['temp_c']) ? (float) $method['temp_c'] : null;

        foreach ($rules as $rule) {
            if (isset($rule['match']) && $standard !== '' && str_contains(str_replace([' ', '-'], '', $standard), $rule['match'])) {
                return $rule['column'];
            }
            if (isset($rule['temp_from']) && $temp !== null && $temp >= $rule['temp_from']) {
                return $rule['column'];
            }
            if (isset($rule['temp_to']) && $temp !== null && $temp < $rule['temp_to']) {
                return $rule['column'];
            }
        }

        return $column;
    }

    /**
     * Crea la muestra, la diagnostica con el motor de su prueba y la guarda.
     * Es el mismo `diagnoseAndSave` que tiene cada controlador de la web,
     * elegido por prueba.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Model, 1: array, 2: ?string}
     */
    private function persist(string $kind, array $config, Transformer $transformer, array $row, array $methods): array
    {
        $model = $config['model'];
        $sample = new $model();
        $sample->forceFill($row + [
            'transformer_id' => $transformer->id,
            'created_by'     => auth()->id(),
        ]);

        $warning = null;

        switch ($kind) {
            case 'chromatography':
                /** @var Chromatographical $sample */
                $sample->setRelation('transformer', $transformer);
                $result = $this->cromas->evaluate($sample);
                $sample->dgaf_score     = $result->score;
                $sample->dgaf_condition = $result->condition;
                $sample->save();

                // Aceite sin cuadro de reglas: el DGAF no se puede calcular. No
                // es un error (el índice de salud cae al DGA Status del IEEE
                // C57.104-2019), pero el laboratorio tiene que saber que la
                // muestra no trae veredicto propio.
                if ($result->condition === null || $result->condition === 'Sin reglas') {
                    $warning = 'chromatography: sin cuadro de reglas para este aceite y tipo de transformador; el diagnóstico usa el respaldo IEEE C57.104-2019.';
                }

                $summary = [
                    'dgaf_score'     => $result->score === null ? null : round($result->score, 4),
                    'dgaf_condition' => $result->condition,
                ];
                break;

            case 'physicochemical':
                /** @var Fiqui $sample */
                // FIELDS y no PARAMS: los métodos alternos (rigidez D877,
                // factor de potencia a 100 °C) SUSTITUYEN al principal cuando
                // son lo único que midió el laboratorio.
                $values = [];
                foreach (Fiqui::FIELDS as $p) {
                    $values[$p] = $sample->{$p} === null ? null : (float) $sample->{$p};
                }
                $r = $this->fiquis->evaluate(
                    $transformer->oilType?->code,
                    $transformer->voltage_kv === null ? null : (float) $transformer->voltage_kv,
                    $values,
                );
                $sample->score     = $r['score'] ?? null;
                $sample->rating    = $r['rating'] ?? null;
                $sample->condition = $r['condition'] ?? null;
                // Constancia de con qué se midió (norma, gap, temperatura). No
                // entra al diagnóstico: la columna elegida ya lo refleja.
                $sample->methods = $methods ?: null;
                $sample->save();

                $summary = ['score' => $r['score'] ?? null, 'condition' => $r['condition'] ?? null];
                break;

            case 'furanos':
                /** @var Furano $sample */
                $r = $this->furanos->evaluate($sample->fal === null ? null : (float) $sample->fal);
                $sample->dp        = $r['dp'];
                $sample->rating    = $r['rating'];
                $sample->condition = $r['condition'];
                $sample->save();

                $summary = ['dp' => $r['dp'], 'condition' => $r['condition']];
                break;

            case 'power_factor':
                /** @var Fpot $sample */
                $r = $this->fpot->evaluate($sample->value === null ? null : (float) $sample->value);
                $sample->rating    = $r['rating'];
                $sample->condition = $r['condition'];
                $sample->save();

                $summary = ['condition' => $r['condition']];
                break;

            default:
                // Inalcanzable: el kind ya se validó contra la config.
                $sample->save();
                $summary = [];
        }

        return [$sample, $summary, $warning];
    }

    /**
     * Laboratorio emisor. Si el código no está en el catálogo del workspace se
     * da de alta: rechazar una medición válida porque falta una fila de
     * catálogo es peor que crearla, y guardarla en null perdería la
     * trazabilidad del informe (que es justamente lo que se vino a arreglar).
     */
    private function resolveLaboratory(array $lab): ?int
    {
        $code = $this->str($lab['laboratory_code'] ?? $lab['laboratory'] ?? null);
        if ($code === null) {
            return null;
        }

        $existing = Laboratory::whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->orWhereRaw('lower(name) = ?', [mb_strtolower($code)])
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return Laboratory::create([
            'name'       => $this->str($lab['laboratory_name'] ?? null) ?? $code,
            'code'       => mb_substr($code, 0, 60),
            'is_active'  => true,
            'created_by' => auth()->id(),
        ])->id;
    }

    private function sampleDate($raw, int $i): Carbon
    {
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                "tests.{$i}.measured_at" => __('validation.date', ['attribute' => 'measured_at']),
            ]);
        }
    }

    private function str($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
