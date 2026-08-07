<?php

namespace Tests\Feature\Diagnostics;

use App\Models\FiquiThreshold;
use App\Models\OilType;
use App\Models\Rule;
use App\Models\RuleCondition;
use App\Models\RuleSet;
use App\Models\Test;
use App\Models\Variable;
use App\Services\Diagnostics\OilRuleCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OilRuleCloner — al crear un aceite nuevo se pueden clonar las reglas (cromas +
 * fiquis) de un aceite existente, para que no quede "Sin reglas".
 */
class OilRuleClonerTest extends TestCase
{
    use RefreshDatabase;

    public function test_clona_rule_sets_y_umbrales_fiquis(): void
    {
        $cromas = Test::create(['code' => 'cromas', 'name' => 'Cromas', 'sort_order' => 1]);
        $var = Variable::create(['code' => 'h2', 'name' => 'H2', 'sort_order' => 1]);

        $source = OilType::create(['slug' => Str::random(22), 'name' => 'Mineral', 'code' => 'mineral']);
        $target = OilType::create(['slug' => Str::random(22), 'name' => 'Ester', 'code' => 'ester']);

        // Cuadro de cromas para el aceite origen + 1 regla + 1 condición.
        $set = RuleSet::create([
            'test_id' => $cromas->id, 'oil_type_id' => $source->id,
            'transformer_type_id' => null, 'total_weight' => 18, 'is_active' => true,
        ]);
        $rule = Rule::create([
            'rule_set_id' => $set->id, 'variable_id' => $var->id,
            'score' => 4, 'weight' => 2, 'priority' => 1, 'is_active' => true,
        ]);
        RuleCondition::create([
            'rule_id' => $rule->id, 'variable_id' => $var->id,
            'operator' => '<', 'value' => 100, 'sort_order' => 1,
        ]);

        // Umbral de fiquis para el aceite origen (por oil_code).
        FiquiThreshold::create([
            'param_key' => 'rig', 'oil_code' => 'mineral', 'voltage_class' => 'low',
            't1' => 45, 't2' => 40, 't3' => 35,
        ]);

        $result = app(OilRuleCloner::class)->clone($source, $target);

        $this->assertSame(1, $result['rule_sets']);
        $this->assertSame(1, $result['thresholds']);

        // El destino ahora tiene su cuadro con la regla y la condición clonadas.
        $newSet = RuleSet::where('oil_type_id', $target->id)->first();
        $this->assertNotNull($newSet);
        $this->assertSame(1, $newSet->rules()->count());
        $this->assertSame(1, $newSet->rules()->first()->conditions()->count());

        // Y su umbral de fiquis con el nuevo oil_code.
        $this->assertTrue(FiquiThreshold::where('oil_code', 'ester')->where('param_key', 'rig')->exists());
    }

    public function test_no_duplica_si_el_destino_ya_tiene_reglas(): void
    {
        $cromas = Test::create(['code' => 'cromas', 'name' => 'Cromas', 'sort_order' => 1]);
        $source = OilType::create(['slug' => Str::random(22), 'name' => 'Mineral', 'code' => 'mineral']);
        $target = OilType::create(['slug' => Str::random(22), 'name' => 'Ester', 'code' => 'ester']);

        RuleSet::create(['test_id' => $cromas->id, 'oil_type_id' => $source->id, 'total_weight' => 18, 'is_active' => true]);
        // El destino YA tiene un cuadro → no debe clonar (idempotente).
        RuleSet::create(['test_id' => $cromas->id, 'oil_type_id' => $target->id, 'total_weight' => 18, 'is_active' => true]);

        $result = app(OilRuleCloner::class)->clone($source, $target);

        $this->assertSame(0, $result['rule_sets']);
        $this->assertSame(1, RuleSet::where('oil_type_id', $target->id)->count());
    }
}
