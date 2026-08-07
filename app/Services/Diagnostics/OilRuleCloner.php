<?php

namespace App\Services\Diagnostics;

use App\Models\FiquiThreshold;
use App\Models\OilType;
use Illuminate\Support\Facades\DB;

/**
 * OilRuleCloner — copia las reglas de diagnóstico de un aceite ORIGEN a un aceite
 * DESTINO, para que un aceite nuevo no quede "Sin reglas". Clona:
 *   - rule_sets (cromas) + sus rules + rule_conditions  (keyed por oil_type_id).
 *   - fiqui_thresholds (fisicoquímico)                  (keyed por oil_code).
 * Furanos y Factor no dependen del aceite → no hay nada que clonar.
 *
 * Idempotente: si el destino YA tiene reglas para una prueba, no duplica.
 * Después de clonar, el usuario ajusta los valores; arrancan con los del origen.
 */
class OilRuleCloner
{
    /** @return array{rule_sets:int,thresholds:int} cuántas filas se clonaron. */
    public function clone(OilType $source, OilType $target): array
    {
        if ($source->id === $target->id) {
            return ['rule_sets' => 0, 'thresholds' => 0];
        }

        return DB::transaction(fn () => [
            'rule_sets'  => $this->cloneRuleSets($source, $target),
            'thresholds' => $this->cloneFiquiThresholds($source, $target),
        ]);
    }

    /** Clona cuadros de cromas (rule_sets) + rules + rule_conditions. */
    private function cloneRuleSets(OilType $source, OilType $target): int
    {
        // No duplicar si el destino ya tiene cuadros.
        if (\App\Models\RuleSet::where('oil_type_id', $target->id)->exists()) {
            return 0;
        }

        $sets = \App\Models\RuleSet::with('rules.conditions')
            ->where('oil_type_id', $source->id)->get();

        $count = 0;
        foreach ($sets as $set) {
            $newSet = $set->replicate();
            $newSet->oil_type_id = $target->id;
            $newSet->save();

            foreach ($set->rules as $rule) {
                $newRule = $rule->replicate();
                $newRule->rule_set_id = $newSet->id;
                $newRule->save();

                foreach ($rule->conditions as $cond) {
                    $newCond = $cond->replicate();
                    $newCond->rule_id = $newRule->id;
                    $newCond->save();
                }
            }
            $count++;
        }

        return $count;
    }

    /** Clona los umbrales de fisicoquímico (por oil_code). */
    private function cloneFiquiThresholds(OilType $source, OilType $target): int
    {
        if (!$source->code || !$target->code) {
            return 0;
        }
        if (FiquiThreshold::where('oil_code', $target->code)->exists()) {
            return 0;
        }

        $rows = FiquiThreshold::where('oil_code', $source->code)->get();
        $count = 0;
        foreach ($rows as $row) {
            $new = $row->replicate();
            $new->oil_code = $target->code;
            $new->save();
            $count++;
        }

        return $count;
    }
}
