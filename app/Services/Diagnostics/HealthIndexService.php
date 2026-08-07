<?php

namespace App\Services\Diagnostics;

use App\Models\ResultScale;
use App\Models\Test;
use App\Models\Transformer;

/**
 * HealthIndexService — calcula el Índice de Salud (HI) de un transformador.
 *
 * Reemplaza el cálculo del sistema viejo, que estaba MAL: usaba un denominador
 * fijo (las 3 pruebas siempre) y las pruebas faltantes valían 0. Resultado: un
 * trafo con solo cromatografía perfecta daba ~47% ("Malo") en vez de 100%.
 *
 * Fórmula correcta (Hitachi) con PESO DINÁMICO:
 *
 *     HI = Σ(peso × rating) / Σ(peso × 4) × 100
 *
 * donde el denominador SOLO suma las pruebas que tienen datos.
 *
 * Todo lo que cambia vive en datos:
 *   - El peso de cada prueba: tests.hi_weight.
 *   - Qué pruebas entran hoy: tests.hi_enabled.
 *   - La escala (semáforo) del HI: result_scales con test_id NULL.
 *   - Los umbrales de cada prueba: rules / result_scales.
 * El código solo tiene las fórmulas.
 *
 * Además del índice, devuelve un COMPONENTE por prueba (rating, condición, color,
 * fecha de la última muestra, peso y % de aporte) que alimenta el dashboard de
 * resumen del transformador. Agregar una prueba nueva = una entrada en
 * componentFor() + sus datos; el resto (HI, dashboard) la toma solo.
 */
class HealthIndexService
{
    /** Condición (código estable) → rating entero canónico. 4=mejor, 0=peor. */
    private const RATING_BY_CONDITION = [
        'Muy Bueno' => 4, 'Bueno' => 3, 'Medio' => 2, 'Malo' => 1, 'Muy Malo' => 0,
    ];

    /** Código de zona Duval → familia de falla (para el dashboard de flota). */
    private const FAULT_FAMILY = [
        'PD' => 'pd',
        'D1' => 'discharge', 'D2' => 'discharge',
        'T1' => 'thermal', 'T2' => 'thermal', 'T3' => 'thermal',
        'DT' => 'mixed',
    ];

    /** Gases del TDCG (IEEE C57.104) — excluye CO2 a propósito. */
    private const TDCG_GASES = ['h2', 'ch4', 'co', 'c2h4', 'c2h6', 'c2h2'];

    /** DP de fin de vida del papel (IEEE/IEC): por debajo, aislamiento agotado. */
    private const PAPER_EOL_DP = 200;

    /** Span mínimo (días) del historial de furanos para extrapolar el DP. */
    private const MIN_DP_SPAN_DAYS = 90;

    /**
     * Días mínimos entre las 2 últimas muestras para calcular tasa de gas.
     * Annualizar un delta de pocos días es ruido (un cambio de 1 día ×30.44
     * da una tasa falsa enorme). Por debajo de esto no se calcula la tasa.
     */
    private const MIN_GASSING_DAYS = 15;

    public function __construct(
        protected ChromatographyEngine $cromas,
    ) {}

    private static function ratingForCondition(?string $condition): ?int
    {
        return $condition === null ? null : (self::RATING_BY_CONDITION[$condition] ?? null);
    }

    /**
     * Tendencia de salud: dirección del DGAF de cromatografía entre las 2 últimas
     * muestras. La cromatografía es la prueba dominante y la "subida de gases" es
     * la señal de alarma temprana. DGAF mayor = peor.
     *
     * @return 'worsening'|'improving'|'stable'|null  (null si <2 muestras con datos)
     */
    public function cromasTrend(Transformer $transformer): ?string
    {
        $samples = $transformer->chromatographicals()->take(2)->get(); // ya viene desc por fecha
        if ($samples->count() < 2) {
            return null;
        }

        // Evitar re-resolver el trafo por cada muestra (ya lo tenemos).
        $samples->each(fn ($s) => $s->setRelation('transformer', $transformer));

        $latest = $this->cromas->evaluate($samples[0]);
        $prev   = $this->cromas->evaluate($samples[1]);
        if (!$latest->isComplete() || !$prev->isComplete()) {
            return null;
        }

        $eps   = (float) config('transformers.trend_dgaf_epsilon', 0.3);
        $delta = $latest->score - $prev->score; // DGAF: mayor = peor
        if ($delta > $eps)  return 'worsening';
        if ($delta < -$eps) return 'improving';
        return 'stable';
    }

    /**
     * Proyección: si el DGAF de cromas viene subiendo, ¿en cuántos meses cruza
     * a la siguiente banda peor del semáforo? Ajuste lineal (mínimos cuadrados)
     * sobre las últimas muestras; el umbral destino sale de result_scales
     * (DATOS), no está clavado. Es una extrapolación indicativa, no un
     * diagnóstico: asume que el ritmo actual se mantiene.
     *
     * @return array{months: float, target: string}|null  null si no empeora,
     *         faltan muestras, o ya está en la peor banda.
     */
    public function cromasForecast(Transformer $transformer): ?array
    {
        $samples = $transformer->chromatographicals()->take(6)->get(); // desc por fecha
        if ($samples->count() < 2) {
            return null;
        }
        $samples->each(fn ($s) => $s->setRelation('transformer', $transformer));

        // Puntos (días, DGAF) de las muestras con diagnóstico completo.
        $points = [];
        $testId = null;
        foreach ($samples as $s) {
            $r = $this->cromas->evaluate($s);
            if (!$r->isComplete() || !$s->sample_date) {
                continue;
            }
            $points[] = ['t' => $s->sample_date->timestamp / 86400, 'y' => $r->score];
        }
        if (count($points) < 2) {
            return null;
        }

        // Pendiente por mínimos cuadrados (DGAF por día).
        $n = count($points);
        $mt = array_sum(array_column($points, 't')) / $n;
        $my = array_sum(array_column($points, 'y')) / $n;
        $num = 0.0; $den = 0.0;
        foreach ($points as $p) {
            $num += ($p['t'] - $mt) * ($p['y'] - $my);
            $den += ($p['t'] - $mt) ** 2;
        }
        if ($den <= 0) {
            return null;
        }
        $slope = $num / $den;
        if ($slope <= 0) {
            return null; // estable o mejorando: no hay nada que proyectar
        }

        // DGAF actual = el de la muestra más reciente (mayor t).
        usort($points, fn ($a, $b) => $b['t'] <=> $a['t']);
        $current = $points[0]['y'];

        // Umbral destino: el inicio (score_from) de la siguiente banda PEOR del
        // semáforo de cromas (result_scales = datos editables).
        $cromasTestId = Test::where('code', 'cromas')->value('id');
        $target = ResultScale::where('test_id', $cromasTestId)
            ->whereNotNull('score_from')
            ->where('score_from', '>', $current)
            ->orderBy('score_from')
            ->first(['score_from', 'condition_label']);
        if (!$target) {
            return null; // ya está en la peor banda
        }

        $days = ((float) $target->score_from - $current) / $slope;

        return [
            'months' => round($days / 30.44, 1),
            'target' => $target->condition_label,
        ];
    }

    /**
     * Diagnósticos cacheados para las agregaciones del dashboard de flota:
     * tipo de falla (Duval), tasa de generación de gas (TDCG ppm/mes), grado
     * de polimerización del papel (furanos) y condición IEEE C57.104. Se
     * recalculan al evaluar el HI; el dashboard solo lee columnas.
     *
     * @return array{fault_type:?string, gassing_rate:?float, paper_dp:?int, ieee_condition:?int}
     */
    public function fleetDiagnostics(Transformer $transformer): array
    {
        $out = ['fault_type' => null, 'gassing_rate' => null, 'paper_dp' => null, 'paper_life_years' => null, 'ieee_condition' => null];

        // Papel: DP (Chendong) del último furano con 2-FAL medido = vida
        // remanente del aislamiento. El `dp` no se persiste; se deriva de fal.
        $furano = $transformer->latestFurano();
        if ($furano && $furano->fal !== null) {
            $out['paper_dp'] = app(FuranoDiagnosisService::class)->evaluate((float) $furano->fal)['dp'];
        }

        // Vida remanente del papel: extrapola la caída del DP desde el historial
        // de furanos (el DP baja con el envejecimiento). Con ≥2 muestras y span
        // suficiente, estima años hasta DP<200. null si no envejece, no hay
        // historial, o ya está en fin de vida.
        $out['paper_life_years'] = $this->paperLifeYears($transformer);

        // Cromatografía: las 2 últimas muestras (ya vienen desc por fecha).
        $samples = $transformer->chromatographicals()->take(2)->get();
        $latest = $samples->first();
        if (!$latest) {
            return $out;
        }
        $latest->setRelation('transformer', $transformer);

        // DGA Status IEEE C57.104-2019 (1=normal · 2 · 3=peor) de la muestra más
        // reciente, usando todo el historial (tasa de generación, Tabla 4) + la
        // edad del trafo. Reemplaza la "Condición 1-4" de la edición 1991: misma
        // invocación que la capa visible (TransformerShowPayload::cromasDgaStatus).
        // El campo cacheado sigue llamándose `ieee_condition`, pero ahora guarda el
        // Status 2019 (1-3), no la Condición 1991 (1-4).
        $age = $transformer->manufacture_year
            ? max(0, now()->year - (int) $transformer->manufacture_year)
            : null;
        $history = $transformer->chromatographicals()->orderBy('sample_date')->get();
        $dga = app(IeeeDgaStatusService::class)->evaluate($history, $age, $transformer->dga_rate_sample_ids);
        if ($dga) {
            $out['ieee_condition'] = (int) $dga['status'];
        }

        // Tipo de falla: si el status es normal (1) no hay falla que nombrar; si
        // no, se clasifica con el Triángulo 1 de Duval (mineral) o T3 (otros).
        if ($out['ieee_condition'] !== null && $out['ieee_condition'] <= 1) {
            $out['fault_type'] = 'normal';
        } else {
            $duval = app(DuvalService::class)->evaluate($latest);
            $code = $duval['triangles']['T1']['zone'] ?? $duval['triangles']['T3']['zone'] ?? null;
            $out['fault_type'] = self::FAULT_FAMILY[$code] ?? ($code ? 'other' : null);
        }

        // Tasa de generación de gas (TDCG ppm/mes) entre las 2 últimas muestras.
        // Solo la subida (generación); estable/bajando → 0. Exige una ventana
        // mínima entre muestras: extrapolar un delta de pocos días a ppm/mes es
        // ruido (genera tasas falsas enormes en trafos sanos muestreados seguido).
        if ($samples->count() >= 2 && $samples[0]->sample_date && $samples[1]->sample_date) {
            $days = $samples[1]->sample_date->diffInDays($samples[0]->sample_date);
            if ($days >= self::MIN_GASSING_DAYS) {
                $tdcg = fn ($s) => array_sum(array_map(fn ($g) => (float) ($s->$g ?? 0), self::TDCG_GASES));
                $rate = ($tdcg($samples[0]) - $tdcg($samples[1])) / $days * 30.44;
                $out['gassing_rate'] = round(max(0, $rate), 2);
            }
        }

        return $out;
    }

    /**
     * Años estimados hasta el fin de vida del papel (DP<200), extrapolando la
     * tendencia del DP (Chendong) desde las últimas muestras de furanos. Solo
     * si el papel envejece (pendiente negativa) y hay span suficiente; null en
     * caso contrario. Tope informativo de 99 años.
     */
    private function paperLifeYears(Transformer $transformer): ?float
    {
        $furanos = $transformer->furanos()->whereNotNull('fal')->take(6)->get(); // desc por fecha
        if ($furanos->count() < 2) {
            return null;
        }

        $fserv = app(FuranoDiagnosisService::class);
        $points = [];
        foreach ($furanos as $f) {
            if (!$f->sample_date) {
                continue;
            }
            $dp = $fserv->evaluate((float) $f->fal)['dp'];
            if ($dp === null) {
                continue;
            }
            $points[] = ['t' => $f->sample_date->timestamp / 86400, 'y' => (float) $dp];
        }
        if (count($points) < 2) {
            return null;
        }

        $ts = array_column($points, 't');
        if ((max($ts) - min($ts)) < self::MIN_DP_SPAN_DAYS) {
            return null; // span muy corto: la pendiente del DP sería ruido
        }

        // Regresión lineal DP vs días (pendiente = DP/día; negativa = envejece).
        $n = count($points);
        $mt = array_sum($ts) / $n;
        $my = array_sum(array_column($points, 'y')) / $n;
        $num = 0.0; $den = 0.0;
        foreach ($points as $p) {
            $num += ($p['t'] - $mt) * ($p['y'] - $my);
            $den += ($p['t'] - $mt) ** 2;
        }
        if ($den <= 0) {
            return null;
        }
        $slope = $num / $den;
        if ($slope >= 0) {
            return null; // DP estable o subiendo (¿aceite tratado?): no proyecta
        }

        // DP actual = el de la muestra más reciente (mayor t).
        usort($points, fn ($a, $b) => $b['t'] <=> $a['t']);
        $current = $points[0]['y'];
        $days = ($current - self::PAPER_EOL_DP) / (-$slope); // hasta DP=200

        return min(round(max(0, $days) / 365.25, 1), 99.0);
    }

    /**
     * Calcula el HI del transformador y (opcional) lo persiste en su caché.
     *
     * $notify: al persistir, si la salud CAMBIÓ a peor (entró en riesgo o
     * empezó a empeorar) dispara la alerta in-app del workspace. Los seeders
     * de backfill masivo pasan false para no inundar la campana.
     */
    public function evaluate(Transformer $transformer, bool $persist = true, bool $notify = true): HealthIndexResult
    {
        $tests = Test::where('hi_enabled', true)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $maxRating = (float) config('diagnostics.max_rating', 4); // techo de la escala (5 niveles → 4)
        $num = 0.0;            // Σ(peso × rating)
        $den = 0.0;            // Σ(peso × max) — dinámico
        $presentWeight = 0.0;  // Σ(peso) de pruebas con datos (para el % de aporte)
        $components = [];
        $missing = [];

        foreach ($tests as $test) {
            $weight = (float) $test->hi_weight;
            $c = $this->componentFor($transformer, $test->code);
            $present = $c !== null && ($c['rating'] ?? null) !== null;

            $components[$test->code] = [
                'code'      => $test->code,
                'name'      => $test->name,
                'weight'    => $weight,
                'present'   => $present,
                'rating'    => $present ? $c['rating'] : null,
                'condition' => $present ? ($c['condition'] ?? null) : null,
                'color'     => $present ? ($c['color'] ?? null) : null,
                'date'      => $c['date'] ?? null,
                'detail'    => $c['detail'] ?? null,
                'share'     => null, // se completa abajo
            ];

            if (!$present) {
                $missing[] = $test->code;
                continue;
            }

            $num += $weight * $c['rating'];
            $den += $weight * $maxRating;
            $presentWeight += $weight;
        }

        // % de aporte de cada prueba presente al índice (peso dinámico).
        if ($presentWeight > 0) {
            foreach ($components as &$comp) {
                if ($comp['present']) {
                    $comp['share'] = round($comp['weight'] / $presentWeight * 100, 1);
                }
            }
            unset($comp);
        }

        $index = $den > 0 ? ($num / $den) * 100.0 : null;
        $scale = $index !== null ? $this->resolveScale($index) : null;

        $result = new HealthIndexResult(
            index: $index,
            condition: $scale?->condition_label,
            color: $scale?->color,
            components: $components,
            missing: $missing,
        );

        if ($persist) {
            // Estado previo (para detectar transiciones y alertar).
            $oldRating = $transformer->health_rating === null ? null : (int) $transformer->health_rating;
            $oldTrend  = $transformer->health_trend;

            // La banda del HI ya trae su rating (0-4). Si una base vieja lo tiene
            // en null, se deriva del texto (compat) — pero ya no depende de la palabra.
            $newRating = $scale === null
                ? null
                : ($scale->hi_rating !== null ? (int) $scale->hi_rating : self::ratingForCondition($scale->condition_label));
            $newTrend  = $this->cromasTrend($transformer);
            // La caché de flota es SECUNDARIA: nunca debe romper el guardado del
            // HI (que corre al guardar cada muestra). Si un motor falla con datos
            // raros, logueamos y dejamos la caché en null; el HI se persiste igual.
            try {
                $fleet = $this->fleetDiagnostics($transformer);
            } catch (\Throwable $e) {
                \Log::warning('fleetDiagnostics failed; HI cache left null', [
                    'transformer_id' => $transformer->id,
                    'error'          => $e->getMessage(),
                ]);
                $fleet = ['fault_type' => null, 'gassing_rate' => null, 'paper_dp' => null, 'paper_life_years' => null, 'ieee_condition' => null];
            }

            // Pronóstico de riesgo a corto plazo: SOLO para los que empeoran (el
            // resto no proyecta). Se cachea aquí para que el dashboard NO recalcule
            // ~40 trafos en vivo en cada carga (era el cuello de botella).
            $forecast = null;
            if ($newTrend === 'worsening') {
                try {
                    $forecast = $this->cromasForecast($transformer);
                } catch (\Throwable $e) {
                    $forecast = null;
                }
            }

            $transformer->forceFill([
                'health_index'  => $index === null ? null : round($index, 2),
                // Canónico: rating ENTERO 0-4 (neutro de idioma + ordenable). La
                // palabra se traduce en la UI vía i18n (diagnostics.cond_*). El
                // semáforo del HI no usa hi_rating, así que se deriva de la
                // condición (las 5 bandas son una enumeración fija 4..0).
                'health_rating' => $newRating,
                // Tendencia (alarma temprana): hacia dónde va el DGAF de cromas.
                'health_trend'  => $newTrend,
                // Caché de flota (para agregaciones rápidas del dashboard).
                'fault_type'    => $fleet['fault_type'],
                'gassing_rate'  => $fleet['gassing_rate'],
                'paper_dp'      => $fleet['paper_dp'],
                'paper_life_years' => $fleet['paper_life_years'],
                'ieee_condition' => $fleet['ieee_condition'],
                // Pronóstico cacheado (lo lee el dashboard sin recalcular).
                'forecast_months' => $forecast['months'] ?? null,
                'forecast_target' => $forecast['target'] ?? null,
            ])->saveQuietly();

            if ($notify) {
                app(HealthAlertService::class)->maybeNotify(
                    $transformer, $oldRating, $oldTrend, $newRating, $newTrend,
                );
            }
        }

        return $result;
    }

    /**
     * Componente de una prueba: rating 0-4, condición, color, fecha de la última
     * muestra y detalle. Null si no hay datos. Despacha por código de prueba.
     *
     * Hoy hay motor para cromas, furanos y fiquis. fpot devuelve null hasta
     * portarse; al hacerlo, el HI y el dashboard lo toman solo.
     */
    protected function componentFor(Transformer $transformer, string $testCode): ?array
    {
        return match ($testCode) {
            'cromas'  => $this->cromasComponent($transformer),
            'furanos' => $this->furanosComponent($transformer),
            'fiquis'  => $this->fiquisComponent($transformer),
            'fpot'    => $this->fpotComponent($transformer),
            default   => null,
        };
    }

    /**
     * Componente de factor de potencia: ubica el último num_fac en la escala de
     * fpot. Solo aporta al HI si el test fpot tiene hi_enabled=true (por defecto
     * está en false, igual que el sistema viejo).
     */
    protected function fpotComponent(Transformer $transformer): ?array
    {
        $sample = $transformer->latestFpot();
        if ($sample === null || $sample->value === null) {
            return null;
        }

        $test = Test::where('code', 'fpot')->first();
        if (!$test) {
            return null;
        }

        $value = (float) $sample->value;
        foreach (\App\Support\Diagnostics\ResultScaleResolver::bands($test->id) as $scale) {
            if ($scale->contains($value)) {
                return [
                    'rating'    => $scale->hi_rating === null ? null : (float) $scale->hi_rating,
                    'condition' => $scale->condition_label,
                    'color'     => $scale->color,
                    'date'      => optional($sample->sample_date)->format('Y-m-d'),
                    'detail'    => ['value' => round($value, 3)],
                ];
            }
        }

        return null;
    }

    /**
     * Componente fisicoquímico: corre el FiquisDiagnosisService sobre la última
     * muestra, según el aceite y la tensión del transformador.
     */
    protected function fiquisComponent(Transformer $transformer): ?array
    {
        $sample = $transformer->latestFiqui();
        if (!$sample) {
            return null;
        }

        $oilCode = $transformer->oilType?->code;
        $values = [];
        // FIELDS, no PARAMS: los métodos alternos sustituyen al principal cuando
        // es el único que midió el laboratorio.
        foreach (\App\Models\Fiqui::FIELDS as $p) {
            $values[$p] = $sample->{$p} === null ? null : (float) $sample->{$p};
        }

        $r = app(FiquisDiagnosisService::class)->evaluate(
            $oilCode,
            $transformer->voltage_kv === null ? null : (float) $transformer->voltage_kv,
            $values
        );
        if ($r === null) {
            return null;
        }

        return [
            'rating'    => $r['rating'],
            'condition' => $r['condition'],
            'color'     => $r['color'],
            'date'      => optional($sample->sample_date)->format('Y-m-d'),
            'detail'    => ['score' => $r['score'], 'class' => $r['class']],
        ];
    }

    /** Componente de cromatografía: corre el motor sobre la última muestra. */
    protected function cromasComponent(Transformer $transformer): ?array
    {
        $sample = $transformer->latestChromatographical();
        if (!$sample) {
            return null;
        }
        // El motor resuelve el rule_set con el aceite/tipo del trafo: fijar la
        // relación evita un lazy-load (N+1) que además el scope de tenant anula
        // en CLI (sin tenant resuelto) → resolveRuleSet(null).
        $sample->setRelation('transformer', $transformer);

        $r = $this->cromas->evaluate($sample);

        // CASO NORMAL: el aceite tiene cuadro de reglas (DGAF) → rating del motor.
        if ($r->hiRating !== null) {
            return [
                'rating'    => $r->hiRating,
                'condition' => $r->condition,
                'color'     => $r->color,
                'date'      => optional($sample->sample_date)->format('Y-m-d'),
                'detail'    => ['dgaf' => round($r->score, 2)],
            ];
        }

        // RESPALDO — aceite SIN cuadro de reglas (ej. silicona/ésteres aún sin
        // calibrar). NO excluir la prueba del HI: excluirla daba "100 Excelente"
        // ocultando una muestra que el IEEE marca peligrosa (era un bug crítico de
        // seguridad). Caemos al DGA Status IEEE C57.104-2019, que clasifica por
        // límites por gas y NO depende del aceite. Mapeo Status(1-3) → rating(0-4):
        //   1 normal → Muy Bueno · 2 precaución → Medio · 3 investigar → Malo.
        $age = $transformer->manufacture_year
            ? max(0, now()->year - (int) $transformer->manufacture_year)
            : null;
        $history = $transformer->chromatographicals()->orderBy('sample_date')->get();
        $dga = app(IeeeDgaStatusService::class)->evaluate($history, $age, $transformer->dga_rate_sample_ids);
        if (!$dga || ($dga['status'] ?? null) === null) {
            return null; // ni el IEEE pudo evaluar → realmente sin datos útiles
        }
        [$rating, $cond, $color] = match ((int) $dga['status']) {
            1       => [4, 'Muy Bueno', 'green'],
            2       => [2, 'Medio', 'yellow'],
            default => [1, 'Malo', 'orange'],
        };

        return [
            'rating'    => $rating,
            'condition' => $cond,
            'color'     => $color,
            'date'      => optional($sample->sample_date)->format('Y-m-d'),
            // Marca de respaldo: la UI/PDF lo muestran como "evaluado por IEEE
            // (sin cuadro de reglas para este aceite)".
            'detail'    => ['ieee_status' => (int) $dga['status'], 'fallback' => 'ieee_c57104'],
        ];
    }

    /**
     * Componente de furanos: ubica fal (2-furfuraldehído) en ppm (fal_ppb/1000)
     * en la escala de furanos. La conversión ÷1000 es fórmula; umbrales = datos.
     */
    protected function furanosComponent(Transformer $transformer): ?array
    {
        $sample = $transformer->latestFurano();
        $ppm = $sample?->falPpm();
        if ($ppm === null) {
            return null;
        }

        $test = Test::where('code', 'furanos')->first();
        if (!$test) {
            return null;
        }

        foreach (\App\Support\Diagnostics\ResultScaleResolver::bands($test->id) as $scale) {
            if ($scale->contains($ppm)) {
                return [
                    'rating'    => $scale->hi_rating === null ? null : (float) $scale->hi_rating,
                    'condition' => $scale->condition_label,
                    'color'     => $scale->color,
                    'date'      => optional($sample->sample_date)->format('Y-m-d'),
                    'detail'    => ['fal_ppb' => round((float) $sample->fal, 1)],
                ];
            }
        }

        return null;
    }

    /**
     * Ubica el HI (0-100) en su escala. A diferencia de cromas (donde menos es
     * mejor y el tramo es [from, to)), el HI usa "tope inclusivo" como el sistema
     * viejo: <=30 Muy Malo, >30 y <=50 Malo, etc. La escala vive en result_scales
     * con test_id NULL (escala global del HI).
     */
    protected function resolveScale(float $index): ?ResultScale
    {
        $scales = \App\Support\Diagnostics\ResultScaleResolver::bands(null);

        foreach ($scales as $scale) {
            $to = $scale->score_to === null ? INF : (float) $scale->score_to;
            if ($index <= $to) {
                return $scale;
            }
        }

        return $scales->last();
    }
}
