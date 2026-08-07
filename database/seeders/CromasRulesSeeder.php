<?php

namespace Database\Seeders;

use App\Models\OilType;
use App\Models\Rule;
use App\Models\RuleCondition;
use App\Models\RuleSet;
use App\Models\Standard;
use App\Models\Test;
use App\Models\TransformerType;
use App\Models\Variable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Carga las reglas de cromatografía (los escalones de score por gas).
 *
 * Lee los datos desde database/seeders/data/cromas_rules.json — así los valores
 * (umbrales, scores, pesos) se pueden ajustar editando el JSON, sin tocar PHP.
 * Esto ES la externalización: las condiciones del sistema viejo, ahora como datos.
 *
 * Cada fila del JSON = un escalón:
 *   { oil, trafo, gas, from, to, score, weight }
 *
 * Se agrupan en rule_sets por (cromas + aceite + trafo). Cada escalón se vuelve
 * una Rule con dos RuleConditions (> from  Y  <= to), o una sola si to es null.
 */
class CromasRulesSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/data/cromas_rules.json');
        if (!file_exists($jsonPath)) {
            $this->command->error("No se encontró cromas_rules.json en $jsonPath");
            return;
        }

        $rows = json_decode(file_get_contents($jsonPath), true);
        if (!$rows) {
            $this->command->error('cromas_rules.json está vacío o mal formado.');
            return;
        }

        $cromas   = Test::where('code', 'cromas')->firstOrFail();
        $iec      = Standard::where('code', 'iec_60599')->first();
        $oilIds   = OilType::pluck('id', 'code');
        $trafoIds = TransformerType::pluck('id', 'code');
        $varIds   = Variable::pluck('id', 'code');

        // Limpia reglas previas de cromas (idempotente: re-seedear no duplica).
        $existingSetIds = RuleSet::where('test_id', $cromas->id)->pluck('id');
        if ($existingSetIds->isNotEmpty()) {
            RuleSet::whereIn('id', $existingSetIds)->delete(); // cascade borra rules y conditions
        }

        // Pesos totales por aceite (del código real: mineral/silicona 18, vegetales 17).
        $totalWeight = [
            'mineral' => 18, 'silicona' => 18,
            'vegetal_soya' => 17, 'vegetal_girasol' => 17,
        ];

        DB::transaction(function () use ($rows, $cromas, $iec, $oilIds, $trafoIds, $varIds, $totalWeight) {
            $setCache = [];   // clave "oil|trafo" -> RuleSet
            $priority = [];   // prioridad incremental por set

            foreach ($rows as $r) {
                $oilCode   = $r['oil'];
                $trafoCode = $r['trafo']; // puede ser null
                $key = $oilCode . '|' . ($trafoCode ?? 'all');

                // Crea (o reusa) el rule_set para esta combinación.
                if (!isset($setCache[$key])) {
                    $oilName   = ucfirst(str_replace('_', ' ', $oilCode));
                    $trafoName = $trafoCode ? ucfirst($trafoCode) : 'Todos';
                    $setCache[$key] = RuleSet::create([
                        'test_id'             => $cromas->id,
                        'oil_type_id'         => $oilIds[$oilCode] ?? null,
                        'transformer_type_id' => $trafoCode ? ($trafoIds[$trafoCode] ?? null) : null,
                        'standard_id'         => $iec?->id,
                        'total_weight'        => $totalWeight[$oilCode] ?? null,
                        'label'               => "Cromas · $oilName · $trafoName",
                        'is_active'           => true,
                    ]);
                    $priority[$key] = 0;
                }
                $set = $setCache[$key];

                $variableId = $varIds[$r['gas']] ?? null;
                if (!$variableId) {
                    continue; // gas no reconocido, se omite
                }

                // Crea la regla (un escalón).
                $rule = Rule::create([
                    'rule_set_id'  => $set->id,
                    'variable_id'  => $variableId,
                    'score'        => $r['score'],
                    'weight'       => $r['weight'],
                    'priority'     => $priority[$key]++,
                    'is_active'    => true,
                ]);

                // Condición inferior: valor > from  (si from > 0).
                if (($r['from'] ?? 0) > 0) {
                    RuleCondition::create([
                        'rule_id'     => $rule->id,
                        'variable_id' => $variableId,
                        'operator'    => '>',
                        'value'       => $r['from'],
                        'sort_order'  => 1,
                    ]);
                }
                // Condición superior: valor <= to  (si to no es null).
                if (($r['to'] ?? null) !== null) {
                    RuleCondition::create([
                        'rule_id'     => $rule->id,
                        'variable_id' => $variableId,
                        'operator'    => '<=',
                        'value'       => $r['to'],
                        'sort_order'  => 2,
                    ]);
                }
                // Si from=0 y to=null sería "cualquier valor" (no debería pasar en cromas).
            }
        });

        $sets  = RuleSet::where('test_id', $cromas->id)->count();
        $rules = Rule::whereIn('rule_set_id', RuleSet::where('test_id', $cromas->id)->pluck('id'))->count();
        $this->command->info("Cromatografía: $sets cuadros (rule_sets) y $rules reglas cargadas desde JSON.");
    }
}
