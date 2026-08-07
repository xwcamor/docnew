<?php

namespace App\Services\Diagnostics;

use App\Models\Chromatographical;
use App\Models\ResultScale;
use App\Models\RuleSet;
use App\Models\Test;
use App\Models\Transformer;

/**
 * ChromatographyEngine — el motor de diagnóstico de cromatografía.
 *
 * Reemplaza los ~180 métodos del sistema viejo con UNA sola lógica que lee
 * las reglas desde la base de datos. La fórmula no cambia nunca; lo que cambia
 * (umbrales, pesos, aceites) son datos en rule_sets / rules / rule_conditions.
 *
 * Pasos:
 *   1. Elige el rule_set correcto según el aceite y el tipo de transformador.
 *   2. Para cada gas medido, busca la primera regla cuyas condiciones se cumplen
 *      y toma su score y peso.
 *   3. Promedia: suma(score * peso) / peso_total  =>  DGAF.
 *   4. Ubica el DGAF en la escala (semáforo) y devuelve el resultado.
 *
 * Maneja el caso de gases faltantes sin castigar el resultado: solo promedia
 * sobre los gases que sí vinieron (peso dinámico).
 */
class ChromatographyEngine
{
    /** Diagnostica una muestra concreta. */
    public function evaluate(Chromatographical $sample): DiagnosticResult
    {
        $transformer = $sample->transformer;
        $ruleSet = $this->resolveRuleSet($transformer);

        if (!$ruleSet) {
            return new DiagnosticResult(
                score: 0, condition: 'Sin reglas', color: null, hiRating: null,
                detail: [], missing: [],
            );
        }

        $gases = $sample->measuredGases();          // ['h2' => 4.0, 'ch4' => 57.0, ...]
        $rules = $ruleSet->rules()->with(['variable', 'conditions'])->get();

        // Agrupa las reglas por variable (gas) para evaluar gas por gas.
        $rulesByVar = $rules->groupBy(fn ($r) => $r->variable?->code);

        $sumScoreWeight = 0.0;
        $sumWeight = 0.0;
        $detail = [];
        $missing = [];

        foreach ($rulesByVar as $gasCode => $gasRules) {
            if ($gasCode === null) {
                continue;
            }

            // ¿Se midió este gas? Si no, no entra al promedio (peso dinámico).
            if (!array_key_exists($gasCode, $gases)) {
                $missing[] = $gasCode;
                continue;
            }

            $value = $gases[$gasCode];

            // Busca la primera regla (por prioridad) cuyas condiciones se cumplan.
            $matched = $this->firstMatchingRule($gasRules, $gases, $value);
            if (!$matched) {
                continue;
            }

            $score  = (float) $matched->score;
            $weight = (float) $matched->weight;

            $sumScoreWeight += $score * $weight;
            $sumWeight      += $weight;

            $detail[$gasCode] = [
                'value'  => $value,
                'score'  => $score,
                'weight' => $weight,
            ];
        }

        // Sin NINGÚN gas medido no hay diagnóstico: una muestra con solo la fecha
        // NO es "Bueno" (eso ubicaba 0 en la banda más baja del semáforo). Devuelve
        // condición nula → la UI muestra "—" en vez de un verde falso.
        if ($sumWeight <= 0) {
            return new DiagnosticResult(
                score: 0, condition: null, color: null, hiRating: null,
                detail: $detail, missing: $missing,
            );
        }

        // DGAF = promedio ponderado (peso dinámico: solo cuentan los gases medidos).
        $dgaf = $sumScoreWeight / $sumWeight;

        // Ubica el DGAF en el semáforo.
        $scale = $this->resolveScale($ruleSet->test_id, $dgaf);

        return new DiagnosticResult(
            score: $dgaf,
            condition: $scale?->condition_label,
            color: $scale?->color,
            hiRating: $scale ? (float) $scale->hi_rating : null,
            detail: $detail,
            missing: $missing,
        );
    }

    /**
     * Traza completa del diagnóstico de una muestra: misma matemática que
     * `evaluate()` pero exponiendo el "por qué" para la UI — qué cuadro (norma +
     * aceite + trafo) se usó, qué banda matcheó cada gas (con su umbral, score y
     * peso), la suma ponderada, el DGAF y dónde cae en el semáforo. No persiste.
     *
     * @return array{has_rules:bool,...}
     */
    public function explain(Chromatographical $sample): array
    {
        $transformer = $sample->transformer;
        $ruleSet = $this->resolveRuleSet($transformer);

        if (!$ruleSet) {
            return ['has_rules' => false];
        }
        $ruleSet->loadMissing(['standard', 'oilType', 'transformerType']);

        $gases = $sample->measuredGases();
        $rules = $ruleSet->rules()->with(['variable', 'conditions.variable'])->get();
        $rulesByVar = $rules->groupBy(fn ($r) => $r->variable?->code);

        $perGas = [];
        $sumScoreWeight = 0.0;
        $sumWeight = 0.0;

        foreach ($rulesByVar as $gasCode => $gasRules) {
            if ($gasCode === null) {
                continue;
            }

            // Gas no medido: no entra al promedio (peso dinámico).
            if (!array_key_exists($gasCode, $gases)) {
                $perGas[] = ['gas' => $gasCode, 'measured' => null, 'matched' => null];
                continue;
            }

            $value   = $gases[$gasCode];
            $matched = $this->firstMatchingRule($gasRules, $gases, $value);
            if (!$matched) {
                $perGas[] = ['gas' => $gasCode, 'measured' => $value, 'matched' => null];
                continue;
            }

            $score  = (float) $matched->score;
            $weight = (float) $matched->weight;
            $sumScoreWeight += $score * $weight;
            $sumWeight      += $weight;

            // Umbrales de la banda (operador + valor) para describir el rango.
            $bounds = $matched->conditions->map(fn ($c) => [
                'operator' => $c->operator,
                'value'    => (float) $c->value,
            ])->values()->all();

            $perGas[] = [
                'gas'      => $gasCode,
                'measured' => $value,
                'matched'  => [
                    'score'        => $score,
                    'weight'       => $weight,
                    'priority'     => (int) $matched->priority,
                    'contribution' => round($score * $weight, 2),
                    'bounds'       => $bounds,
                ],
            ];
        }

        $hasData = $sumWeight > 0;          // sin gases medidos no hay diagnóstico
        $dgaf  = $hasData ? ($sumScoreWeight / $sumWeight) : null;
        $scale = $hasData ? $this->resolveScale($ruleSet->test_id, $dgaf) : null;

        // Todo el semáforo, con la banda matcheada marcada.
        $scaleBands = \App\Support\Diagnostics\ResultScaleResolver::bands($ruleSet->test_id)
            ->map(fn ($s) => [
                'from'      => $s->score_from === null ? null : (float) $s->score_from,
                'to'        => $s->score_to === null ? null : (float) $s->score_to,
                'condition' => $s->condition_label,
                'color'     => $s->color,
                'rating'    => $s->hi_rating === null ? null : (float) $s->hi_rating,
                'matched'   => $scale !== null && $s->id === $scale->id,
            ])->all();

        return [
            'has_rules' => true,
            'rule_set'  => [
                'standard'         => $ruleSet->standard?->name,
                'standard_version' => $ruleSet->standard?->version,
                'oil'              => $ruleSet->oilType?->name,
                'trafo'            => $ruleSet->transformerType?->name, // null = aplica a todos
            ],
            'per_gas'          => $perGas,
            'sum_score_weight' => round($sumScoreWeight, 2),
            'sum_weight'       => round($sumWeight, 2),
            'has_data'         => $hasData,
            'dgaf'             => $dgaf === null ? null : round($dgaf, 4),
            'scale'            => $scaleBands,
            'condition'        => $scale?->condition_label,
            'color'            => $scale?->color,
            'rating'           => $scale?->hi_rating === null ? null : (float) $scale->hi_rating,
        ];
    }

    /**
     * Norma del rule_set aplicado al transformador (para citarla en informes).
     * Viene de DATOS (tabla standards vía rule_sets), no del código.
     */
    public function appliedStandard(Transformer $transformer): ?array
    {
        $standard = $this->resolveRuleSet($transformer)?->standard;

        return $standard ? ['name' => $standard->name, 'version' => $standard->version] : null;
    }

    /**
     * Elige el rule_set que corresponde al transformador.
     * Primero intenta (aceite + trafo); si no hay, prueba (aceite + sin trafo);
     * esto cubre aceites como silicona que aplican a todos los trafos.
     */
    protected function resolveRuleSet(Transformer $transformer): ?RuleSet
    {
        $cromas = Test::where('code', 'cromas')->first();
        if (!$cromas) {
            return null;
        }

        // Híbrido por tenant: se prefiere el cuadro PROPIO del tenant del trafo;
        // si no tiene, cae al GLOBAL de fábrica (tenant_id null). Con datos
        // actuales (todo global) siempre resuelve al global → idéntico al actual.
        foreach ([$transformer->tenant_id, null] as $tenantId) {
            $base = RuleSet::where('test_id', $cromas->id)
                ->where('is_active', true)
                ->where('oil_type_id', $transformer->oil_type_id);
            $base = $tenantId === null
                ? $base->whereNull('tenant_id')
                : $base->where('tenant_id', $tenantId);

            // Coincidencia exacta: aceite + tipo de transformador.
            $exact = (clone $base)
                ->where('transformer_type_id', $transformer->transformer_type_id)
                ->first();
            if ($exact) {
                return $exact;
            }

            // Fallback: aceite que aplica a todos los trafos (transformer_type_id null).
            $any = (clone $base)->whereNull('transformer_type_id')->first();
            if ($any) {
                return $any;
            }
        }

        return null;
    }

    /**
     * De un grupo de reglas de un mismo gas, devuelve la primera (por prioridad)
     * cuyas condiciones TODAS se cumplen. $allGases permite condiciones que
     * dependan de otros gases (multi-variable), aunque en escalones simples
     * solo se usa el valor del propio gas.
     */
    protected function firstMatchingRule($gasRules, array $allGases, float $value)
    {
        $ordered = $gasRules->sortBy('priority');

        foreach ($ordered as $rule) {
            $conditions = $rule->conditions;

            // Regla sin condiciones = comodín (no debería pasar en cromas).
            if ($conditions->isEmpty()) {
                return $rule;
            }

            $allMatch = true;
            foreach ($conditions as $cond) {
                // El valor a comparar: el del gas de la condición (normalmente el mismo).
                $condGas = $cond->variable?->code;
                $measured = $condGas && array_key_exists($condGas, $allGases)
                    ? $allGases[$condGas]
                    : $value;

                if (!$cond->matches($measured)) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                return $rule;
            }
        }

        return null;
    }

    /** Ubica un score en la escala de resultado (semáforo) de la prueba. */
    protected function resolveScale(int $testId, float $score): ?ResultScale
    {
        $scales = \App\Support\Diagnostics\ResultScaleResolver::bands($testId);

        foreach ($scales as $scale) {
            if ($scale->contains($score)) {
                return $scale;
            }
        }

        return null;
    }
}
