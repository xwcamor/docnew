<?php

namespace Tests\Feature\Diagnostics;

use App\Models\Chromatographical;
use App\Models\OilType;
use App\Models\RuleSet;
use App\Models\Test as DiagTest;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Services\Diagnostics\ChromatographyEngine;
use Database\Seeders\CromasRulesSeeder;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión: el transformador tipo HORNO con aceite mineral debe diagnosticar.
 *
 * El sistema viejo (chromatographical.rb, "CALCULOS DE TRANSFORMADOR DE HORNO")
 * sí calculaba el horno, pero la extracción a Excel lo omitió y las reglas no se
 * cargaron. Resultado: un trafo mineral+horno devolvía "Sin reglas".
 *
 * Estas reglas usan PESO DINÁMICO (6 gases, sin acetileno, peso total 13) — no el
 * divisor fijo 18 del código viejo, que era inconsistente.
 */
class ChromatographyHornoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DiagnosticCatalogSeeder::class);
        $this->seed(CromasRulesSeeder::class);
    }

    private function diagnose(string $trafoCode, array $gases)
    {
        $oil   = OilType::where('code', 'mineral')->firstOrFail();
        $trafo = TransformerType::where('code', $trafoCode)->firstOrFail();

        $t = new Transformer();
        $t->forceFill([
            'serial' => 'TEST', 'slug' => 'test-' . uniqid(),
            'oil_type_id' => $oil->id, 'transformer_type_id' => $trafo->id,
            'customer_id' => null, 'tenant_id' => null,
        ])->saveQuietly();

        $s = new Chromatographical();
        $s->forceFill(array_merge(
            ['transformer_id' => $t->id, 'sample_date' => now(), 'tenant_id' => null],
            $gases
        ))->saveQuietly();
        $s->setRelation('transformer', $t);

        return (new ChromatographyEngine())->evaluate($s);
    }

    public function test_existe_rule_set_de_horno_mineral(): void
    {
        $cromas = DiagTest::where('code', 'cromas')->firstOrFail();
        $oil    = OilType::where('code', 'mineral')->firstOrFail();
        $horno  = TransformerType::where('code', 'horno')->firstOrFail();

        $this->assertTrue(
            RuleSet::where('test_id', $cromas->id)
                ->where('oil_type_id', $oil->id)
                ->where('transformer_type_id', $horno->id)
                ->exists(),
            'Debe existir un rule_set de cromas para mineral + horno.'
        );
    }

    public function test_horno_diagnostica_y_no_devuelve_sin_reglas(): void
    {
        // Todos los gases en el escalón 1 (score 1) → DGAF = 13/13 = 1.0.
        $r = $this->diagnose('horno', [
            'h2' => 150, 'ch4' => 120, 'c2h4' => 180, 'c2h6' => 100,
            'co' => 700, 'co2' => 5000, 'c2h2' => 0,
        ]);

        $this->assertNotSame('Sin reglas', $r->condition);
        $this->assertSame('Muy Bueno', $r->condition);
        $this->assertEqualsWithDelta(1.0, $r->score, 0.0001);
        // El acetileno no entra al horno: no aparece en el detalle.
        $this->assertArrayNotHasKey('c2h2', $r->detail);
        // Peso dinámico: 6 gases presentes, peso total 13.
        $this->assertCount(6, $r->detail);
    }

    public function test_horno_peor_caso_es_muy_malo(): void
    {
        // Todos los gases por encima del último escalón (score 6) → DGAF = 6.0.
        $r = $this->diagnose('horno', [
            'h2' => 800, 'ch4' => 700, 'c2h4' => 600, 'c2h6' => 350,
            'co' => 2000, 'co2' => 12000, 'c2h2' => 0,
        ]);

        $this->assertSame('Muy Malo', $r->condition);
        $this->assertEqualsWithDelta(6.0, $r->score, 0.0001);
    }

    public function test_potencia_no_se_altero(): void
    {
        // Sanidad: el caso mineral+potencia sigue dando 18/18 = 1.0.
        $r = $this->diagnose('potencia', [
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ]);

        $this->assertSame('Muy Bueno', $r->condition);
        $this->assertEqualsWithDelta(1.0, $r->score, 0.0001);
        $this->assertCount(7, $r->detail);
    }
}
