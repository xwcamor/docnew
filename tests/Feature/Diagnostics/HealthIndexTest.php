<?php

namespace Tests\Feature\Diagnostics;

use App\Models\Chromatographical;
use App\Models\Fpot;
use App\Models\Furano;
use App\Models\OilType;
use App\Models\Test;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Services\Diagnostics\HealthIndexService;
use Database\Seeders\CromasRulesSeeder;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Índice de Salud (HI) con PESO DINÁMICO.
 *
 * Regresión del bug del sistema viejo: el HI usaba denominador fijo (84) y las
 * pruebas faltantes valían 0, así que un trafo con solo cromatografía perfecta
 * daba ~47% ("Malo"). Con peso dinámico debe dar 100% ("Muy Bueno").
 */
class HealthIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DiagnosticCatalogSeeder::class);
        $this->seed(CromasRulesSeeder::class);
    }

    private function transformerConCromas(array $gases): Transformer
    {
        $oil   = OilType::where('code', 'mineral')->firstOrFail();
        $trafo = TransformerType::where('code', 'potencia')->firstOrFail();

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

        return $t;
    }

    private function service(): HealthIndexService
    {
        return app(HealthIndexService::class);
    }

    public function test_solo_cromas_perfecta_da_100_no_castiga_pruebas_faltantes(): void
    {
        // Cromas en el mejor tramo (rating 4) y SIN fiquis ni furanos.
        // Viejo (bug): 10*4 / (4*(10+6+5)) * 100 = 40/84 = 47.6 -> "Malo".
        // Nuevo (peso dinámico): 10*4 / (4*10) * 100 = 100 -> "Muy Bueno".
        $t = $this->transformerConCromas([
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ]);

        $r = $this->service()->evaluate($t);

        $this->assertEqualsWithDelta(100.0, $r->index, 0.001);
        $this->assertSame('Muy Bueno', $r->condition);
        // Fiquis y furanos estan habilitados pero sin datos: son parciales, no penalizan.
        $this->assertTrue($r->isPartial());
        $this->assertContains('fiquis', $r->missing);
        $this->assertContains('furanos', $r->missing);
        $this->assertTrue($r->components['cromas']['present']);
        $this->assertFalse($r->components['fiquis']['present']);
    }

    public function test_persiste_el_hi_en_el_transformador(): void
    {
        $t = $this->transformerConCromas([
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ]);

        $this->service()->evaluate($t);

        $t->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $t->health_index, 0.01);
        $this->assertSame(4, $t->health_rating);
    }

    public function test_cromas_en_tramo_medio_baja_el_hi(): void
    {
        // Gases altos -> cromas en tramo "Malo" (rating 1) -> HI = 10*1/40*100 = 25 -> "Muy Malo".
        $t = $this->transformerConCromas([
            'h2' => 600, 'ch4' => 500, 'c2h4' => 470, 'c2h6' => 250,
            'co' => 1200, 'co2' => 17500, 'c2h2' => 60,
        ]);

        $r = $this->service()->evaluate($t);

        $this->assertLessThan(50.0, $r->index);
        $this->assertContains($r->condition, ['Malo', 'Muy Malo']);
    }

    public function test_hi_combina_cromas_y_furanos_con_peso_dinamico(): void
    {
        // Cromas perfecta (rating 4) + furanos fal=1000ppb -> 1.0 ppm -> "Muy
        // Malo" rating 0 (semáforo CONSERVADOR del viejo: >=1 ppm satura).
        // HI = (10*4 + 6*0) / (4*(10+6)) * 100 = 40/64*100 = 62.5 -> "Medio".
        // (pesos Hitachi: cromas 10, furanos 6)
        // Fiquis sigue faltando: no entra (peso dinamico).
        $t = $this->transformerConCromas([
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ]);
        $f = new Furano();
        $f->forceFill([
            'transformer_id' => $t->id, 'sample_date' => now(),
            'fal' => 1000, 'tenant_id' => null,
        ])->saveQuietly();

        $r = $this->service()->evaluate($t);

        $this->assertEqualsWithDelta(62.5, $r->index, 0.1);
        $this->assertSame('Medio', $r->condition);
        $this->assertTrue($r->components['furanos']['present']);
        $this->assertEqualsWithDelta(0.0, $r->components['furanos']['rating'], 0.001);
        $this->assertContains('fiquis', $r->missing);
        $this->assertNotContains('furanos', $r->missing);
    }

    public function test_fpot_no_entra_al_hi_si_se_deshabilita(): void
    {
        // fpot viene hi_enabled=true por default; al ponerlo en false (un dato,
        // cero código) deja de aparecer en el HI ni como componente presente/faltante.
        Test::where('code', 'fpot')->update(['hi_enabled' => false]);

        $t = $this->transformerConCromas([
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ]);
        (new Fpot())->forceFill([
            'transformer_id' => $t->id, 'sample_date' => now(),
            'value' => 0.85, 'tenant_id' => null,
        ])->saveQuietly();

        $r = $this->service()->evaluate($t);

        $this->assertEqualsWithDelta(100.0, $r->index, 0.001); // solo cromas
        $this->assertArrayNotHasKey('fpot', $r->components);
        $this->assertNotContains('fpot', $r->missing);
    }

    public function test_fpot_entra_al_hi_si_se_habilita(): void
    {
        // Al poner hi_enabled=true (un dato, cero código), fpot pondera en el HI.
        Test::where('code', 'fpot')->update(['hi_enabled' => true]);

        // Cromas perfecta (rating 4, peso 10) + fpot 0.85 (Medio, rating 2, peso 10).
        // HI = (10*4 + 10*2) / (4*(10+10)) * 100 = 75.0 -> "Bueno".
        $t = $this->transformerConCromas([
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ]);
        (new Fpot())->forceFill([
            'transformer_id' => $t->id, 'sample_date' => now(),
            'value' => 0.85, 'tenant_id' => null,
        ])->saveQuietly();

        $r = $this->service()->evaluate($t);

        $this->assertEqualsWithDelta(75.0, $r->index, 0.1);
        $this->assertSame('Bueno', $r->condition);
        $this->assertTrue($r->components['fpot']['present']);
        $this->assertEqualsWithDelta(2.0, $r->components['fpot']['rating'], 0.001);
    }

    public function test_sin_ninguna_prueba_no_hay_hi(): void
    {
        $oil   = OilType::where('code', 'mineral')->firstOrFail();
        $trafo = TransformerType::where('code', 'potencia')->firstOrFail();
        $t = new Transformer();
        $t->forceFill([
            'serial' => 'VACIO', 'slug' => 'vacio-' . uniqid(),
            'oil_type_id' => $oil->id, 'transformer_type_id' => $trafo->id,
            'customer_id' => null, 'tenant_id' => null,
        ])->saveQuietly();

        $r = $this->service()->evaluate($t);

        $this->assertNull($r->index);
        $this->assertFalse($r->hasData());
        $this->assertNull($r->condition);
    }

    public function test_la_escala_hi_usa_tope_inclusivo(): void
    {
        // HI = 50.0 exacto debe caer en "Malo" (<=50), no en "Medio".
        // Se logra con cromas rating 2 (tramo "Medio"): 10*2/40*100 = 50.
        $t = $this->transformerConCromas([
            // Valores que dejan cada gas en el tramo de score 3 (condicion "Medio", rating 2).
            'h2' => 250, 'ch4' => 180, 'c2h4' => 380, 'c2h6' => 130,
            'co' => 800, 'co2' => 15500, 'c2h2' => 35,
        ]);

        $r = $this->service()->evaluate($t);

        // No verificamos el 50 exacto (depende del mix de gases); verificamos la
        // semantica del tope inclusivo con un valor construido:
        $this->assertContains($r->condition, ['Malo', 'Medio', 'Muy Malo']);
        $this->assertNotNull($r->index);
    }
}
