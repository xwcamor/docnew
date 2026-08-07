<?php

namespace Tests\Unit;

use App\Models\Chromatographical;
use App\Services\Diagnostics\DuvalService;
use Tests\TestCase;

class DuvalServiceTest extends TestCase
{
    private DuvalService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DuvalService();
    }

    private function sample(float $h2, float $ch4, float $c2h4, float $c2h6, float $c2h2, ?string $oil = null): Chromatographical
    {
        $c = new Chromatographical();
        $c->h2 = $h2; $c->ch4 = $ch4; $c->c2h4 = $c2h4; $c->c2h6 = $c2h6; $c->c2h2 = $c2h2;
        if ($oil !== null) {
            $tr = new \App\Models\Transformer();
            $tr->setRelation('oilType', new \App\Models\OilType(['code' => $oil]));
            $c->setRelation('transformer', $tr);
        }
        return $c;
    }

    public function test_non_mineral_oil_uses_triangle_3(): void
    {
        // Silicona: corte T1/T2 en C2H4=16 (mineral=20). Un punto con %C2H4=18 y
        // %C2H2<4 es T1 en mineral, pero T2 en silicona (>=16).
        $r = $this->svc->evaluate($this->sample(0, 80, 18, 0, 2, 'silicona'));
        $this->assertArrayHasKey('T3', $r['triangles']);
        $this->assertArrayNotHasKey('T1', $r['triangles']);
        $this->assertSame('T2', $r['triangles']['T3']['zone']);
        $this->assertTrue($r['triangles']['T3']['visible']);
        $this->assertNull($r['pentagon']); // no aplica a no minerales
    }

    public function test_geometry_for_non_mineral_is_triangle_3_only(): void
    {
        $g = $this->svc->geometry('silicona');
        $this->assertArrayHasKey('T3', $g['triangles']);
        $this->assertArrayNotHasKey('T1', $g['triangles']);
        $this->assertSame([], $g['pentagons']);
    }

    /** Triángulo de gases con porcentajes exactos (suman 100) para probar fronteras. */
    private function tri(float $ch4, float $c2h4, float $c2h2): Chromatographical
    {
        return $this->sample(0, $ch4, $c2h4, 0, $c2h2);
    }

    public function test_canonical_triangle1_boundaries(): void
    {
        // PD: %CH4 >= 98.
        $this->assertSame('PD', $this->svc->evaluate($this->tri(98, 1, 1))['triangles']['T1']['zone']);
        // T3: %C2H2 < 15 y %C2H4 >= 50; en 50 cae a T3 (T2 exige < 50).
        $this->assertSame('T3', $this->svc->evaluate($this->tri(48, 50, 2))['triangles']['T1']['zone']);
        // T2: %C2H4 = 49 (< 50) con %C2H2 < 4.
        $this->assertSame('T2', $this->svc->evaluate($this->tri(49, 49, 2))['triangles']['T1']['zone']);
        // T1: %C2H4 < 20 y %C2H2 < 4.
        $this->assertSame('T1', $this->svc->evaluate($this->tri(79, 19, 2))['triangles']['T1']['zone']);
        // D1: %C2H4 < 23 y %C2H2 >= 13.
        $this->assertSame('D1', $this->svc->evaluate($this->tri(77, 10, 13))['triangles']['T1']['zone']);
        // D2: 23 <= %C2H4 < 40 y %C2H2 >= 13.
        $this->assertSame('D2', $this->svc->evaluate($this->tri(50, 30, 20))['triangles']['T1']['zone']);
        // DT: %C2H2 entre 4 y 13 con alta %C2H4 = resto (mezcla).
        $this->assertSame('DT', $this->svc->evaluate($this->tri(45, 45, 10))['triangles']['T1']['zone']);
    }

    public function test_high_ch4_is_partial_discharge_in_triangle_1(): void
    {
        // Mucho CH4 = descarga parcial (zona PD del Triángulo 1).
        $r = $this->svc->evaluate($this->sample(50, 987, 7, 20, 3));
        $this->assertSame('PD', $r['triangles']['T1']['zone']);
        $this->assertTrue($r['triangles']['T1']['visible']);
    }

    public function test_high_c2h4_is_thermal_t3(): void
    {
        $r = $this->svc->evaluate($this->sample(20, 60, 400, 30, 2));
        $this->assertSame('T3', $r['triangles']['T1']['zone']);
    }

    public function test_high_acetylene_is_discharge(): void
    {
        // Mucho C2H2 = descarga eléctrica (D1/D2), nunca térmica.
        $r = $this->svc->evaluate($this->sample(100, 10, 50, 5, 200));
        $this->assertContains($r['triangles']['T1']['zone'], ['D1', 'D2']);
    }

    public function test_visibility_rule_electrical_hides_t4_t5(): void
    {
        // Falla eléctrica (D1): T1 visible, T4 y T5 ocultos.
        $r = $this->svc->evaluate($this->sample(100, 10, 50, 5, 200));
        $this->assertTrue($r['triangles']['T1']['visible']);
        $this->assertFalse($r['triangles']['T4']['visible']);
        $this->assertFalse($r['triangles']['T5']['visible']);
    }

    public function test_visibility_rule_t2_shows_all_three(): void
    {
        // T2 es el único caso con los 3 triángulos visibles a la vez.
        $r = $this->svc->evaluate($this->sample(20, 130, 70, 60, 1));
        $this->assertSame('T2', $r['triangles']['T1']['zone']);
        $this->assertTrue($r['triangles']['T1']['visible']);
        $this->assertTrue($r['triangles']['T4']['visible']);
        $this->assertTrue($r['triangles']['T5']['visible']);
    }

    public function test_visibility_rule_t3_shows_only_t5(): void
    {
        $r = $this->svc->evaluate($this->sample(20, 60, 400, 30, 2));
        $this->assertTrue($r['triangles']['T1']['visible']);
        $this->assertFalse($r['triangles']['T4']['visible']);
        $this->assertTrue($r['triangles']['T5']['visible']);
    }

    public function test_pentagon_resolves_all_three_sets(): void
    {
        $r = $this->svc->evaluate($this->sample(20, 60, 400, 30, 2));
        $this->assertNotNull($r['pentagon']);
        $this->assertArrayHasKey('P1', $r['pentagon']['zones']);
        $this->assertArrayHasKey('P2', $r['pentagon']['zones']);
        $this->assertArrayHasKey('combine', $r['pentagon']['zones']);
        // Los porcentajes de los 5 gases suman ~100.
        $this->assertEqualsWithDelta(100, array_sum($r['pentagon']['pct']), 2);
    }

    public function test_no_gases_returns_nulls(): void
    {
        $r = $this->svc->evaluate($this->sample(0, 0, 0, 0, 0));
        $this->assertNull($r['triangles']['T1']);
        $this->assertNull($r['triangles']['T4']);
        $this->assertNull($r['triangles']['T5']);
        $this->assertNull($r['pentagon']);
    }

    public function test_ltc_triangle2_matches_official_example(): void
    {
        // Punto ejemplo de la hoja oficial 'Triangle 2 LTC' (CH4=2342, C2H4=3518,
        // C2H2=12) → Fault = T3 en el Excel. Reusamos evaluateGases con oil='ltc'.
        $r = $this->svc->evaluateGases(['h2' => 0, 'ch4' => 2342, 'c2h4' => 3518, 'c2h6' => 0, 'c2h2' => 12], 'ltc');
        $this->assertArrayHasKey('T2', $r['triangles']);
        $this->assertArrayNotHasKey('T1', $r['triangles']);
        $this->assertSame('T3', $r['triangles']['T2']['zone']);
        $this->assertTrue($r['triangles']['T2']['visible']);
        $this->assertNull($r['pentagon']);
    }

    public function test_ltc_triangle2_zones(): void
    {
        // Operación normal del LTC: %C2H4 6-23, %CH4 2-19 → mucho C2H2 → N.
        $n = $this->svc->evaluateGases(['h2' => 0, 'ch4' => 8, 'c2h4' => 10, 'c2h6' => 0, 'c2h2' => 82], 'ltc');
        $this->assertSame('N', $n['triangles']['T2']['zone']);
        // C2H2 muy alto, fuera del recuadro normal → arco anormal D1.
        $d1 = $this->svc->evaluateGases(['h2' => 0, 'ch4' => 1, 'c2h4' => 2, 'c2h6' => 0, 'c2h2' => 97], 'ltc');
        $this->assertSame('D1', $d1['triangles']['T2']['zone']);
        // Geometría: las 6 zonas canónicas, proyectadas.
        $g = $this->svc->geometry('ltc');
        $codes = array_map(fn ($z) => $z['code'], $g['triangles']['T2']);
        $this->assertEqualsCanonicalizing(['T3', 'T2', 'X3', 'X1', 'N', 'D1'], $codes);
        $this->assertSame([], $g['pentagons']);
    }

    public function test_midel_uses_triangle3(): void
    {
        // Éster sintético (Midel 7131): Triángulo 3, corte T2/T3 en C2H4=68.
        // %C2H4 alto → T3. (corte d1d2=26/t1t2=39/t2t3=68 del Excel oficial).
        $r = $this->svc->evaluateGases(['h2' => 30, 'ch4' => 40, 'c2h4' => 95, 'c2h6' => 20, 'c2h2' => 3], 'ester_sintetico');
        $this->assertArrayHasKey('T3', $r['triangles']);
        $this->assertSame('T3', $r['triangles']['T3']['zone']);
        $this->assertNull($r['pentagon']);
    }

    /**
     * Casos REALES del paper canónico Cheim/Duval/Haider 2020 (Combined Duval
     * Pentagons, Energies 13, 2859), Tabla 1: 5 transformadores de aceite mineral
     * con falla confirmada por inspección interna, con su zona en el Pentágono 1,
     * Pentágono 2 y el Combinado. Validación de punta a punta contra la fuente
     * primaria (la misma que trae el primer H-J). Tupla = (H2, CH4, C2H2, C2H4, C2H6).
     *
     * Case 4 es BORDERLINE: el paper lo clasifica C/T3-C pero dice textual que
     * "cayó en el borde entre C y T3-H... podría ir para cualquier lado"; nuestro
     * centroide (y el primer H-J) lo ubican del lado T3-H. Se acepta cualquiera.
     */
    public function test_canonical_paper_cases_pentagons(): void
    {
        // [H2, CH4, C2H2, C2H4, C2H6], P1, P2, Combinado, T1-triangulo
        $cases = [
            [[29, 204, 0, 17, 264],          'T1', ['O'],         ['T1-O'],         'T1'],
            [[555, 1050, 29, 3520, 489],     'T3', ['T3-H'],       ['T3-H'],         'T3'],
            [[754, 2647, 6, 2590, 1127],     'T2', ['C'],          ['T2-C'],         'T2'],
            // borderline C / T3-H (paper: C/T3-C; H-J y nuestro motor: T3-H/T3-H)
            [[2070, 31879, 55, 38192, 1127], 'T3', ['C', 'T3-H'],  ['T3-C', 'T3-H'], 'T3'],
            [[6, 46, 0, 9, 12],              'T1', ['C'],          ['T1-C'],         'T1'],
        ];

        foreach ($cases as [$g, $p1, $p2, $comb, $t1]) {
            $gas = ['h2' => $g[0], 'ch4' => $g[1], 'c2h2' => $g[2], 'c2h4' => $g[3], 'c2h6' => $g[4]];
            $r = $this->svc->evaluateGases($gas); // mineral
            $z = $r['pentagon']['zones'];
            $tag = json_encode($g);
            $this->assertSame($p1, $z['P1'], "P1 $tag");
            $this->assertContains($z['P2'], $p2, "P2 $tag");
            $this->assertContains($z['combine'], $comb, "combine $tag");
            $this->assertSame($t1, $r['triangles']['T1']['zone'], "T1 $tag");
        }
    }

    public function test_pentagon_pd_zone_is_reachable(): void
    {
        // Corona/PD: H2 dominante → centroide en el notch de PD (cerca de (0,30)).
        // Regresión: PD se evalúa antes que S (que lo tapaba por estar simplificado).
        $r = $this->svc->evaluateGases(['h2' => 95, 'ch4' => 2, 'c2h4' => 1, 'c2h6' => 3, 'c2h2' => 1]);
        $this->assertSame('PD', $r['pentagon']['zones']['P1']);
        $this->assertSame('PD', $r['pentagon']['zones']['P2']);
        $this->assertSame('PD', $r['pentagon']['zones']['combine']);
    }

    public function test_t4_t5_canonical_from_official_excel(): void
    {
        // Ejemplos de las hojas oficiales del Excel de Duval.
        // T4 (H2/CH4/C2H6): %H2=72, %CH4=21.9, %C2H6=6.1 → S.
        $r = $this->svc->evaluateGases(['h2' => 15204, 'ch4' => 4627, 'c2h6' => 1286, 'c2h4' => 0, 'c2h2' => 0]);
        $this->assertSame('S', $r['triangles']['T4']['zone']);
        // T5 (CH4/C2H4/C2H6): %CH4=42.6, %C2H4=37, %C2H6=20.4 → C.
        $r = $this->svc->evaluateGases(['h2' => 0, 'ch4' => 315, 'c2h4' => 274, 'c2h6' => 151, 'c2h2' => 0]);
        $this->assertSame('C', $r['triangles']['T5']['zone']);

        // Un punto por zona (umbrales canónicos), para fijar la lógica.
        // T4: O = mucho C2H6, poco H2.
        $r = $this->svc->evaluateGases(['h2' => 5, 'ch4' => 30, 'c2h6' => 65, 'c2h4' => 0, 'c2h2' => 0]);
        $this->assertSame('O', $r['triangles']['T4']['zone']);
        // T4: PD = casi todo CH4 (%CH4 2-15), sin C2H6.
        $r = $this->svc->evaluateGases(['h2' => 90, 'ch4' => 9, 'c2h6' => 0, 'c2h4' => 0, 'c2h2' => 0]);
        $this->assertSame('PD', $r['triangles']['T4']['zone']);
        // T5: T3 = mucho C2H4.
        $r = $this->svc->evaluateGases(['h2' => 0, 'ch4' => 20, 'c2h4' => 75, 'c2h6' => 5, 'c2h2' => 0]);
        $this->assertSame('T3', $r['triangles']['T5']['zone']);
        // T5: T2 = C2H4 medio, poco C2H6.
        $r = $this->svc->evaluateGases(['h2' => 0, 'ch4' => 78, 'c2h4' => 20, 'c2h6' => 2, 'c2h2' => 0]);
        $this->assertSame('T2', $r['triangles']['T5']['zone']);
    }

    /** Triángulo 5: una muestra por cada una de sus 7 zonas (O/S/C/T2/T3/PD/ND). */
    public function test_t5_all_zones(): void
    {
        // [ch4, c2h4, c2h6] (ppm = %, suman 100) → zona T5 esperada.
        $cases = [
            [[90, 0, 10],  'PD'], // %C2H4<1, %C2H6 2-15
            [[99, 0, 1],   'O'],  // %C2H4<1, %C2H6<2
            [[50, 5, 45],  'S'],  // %C2H4<10, %C2H6 15-54
            [[50, 30, 20], 'C'],  // %C2H4 10-49, %C2H6 12-30
            [[78, 20, 2],  'T2'], // %C2H4 10-35, %C2H6<12
            [[10, 85, 5],  'T3'], // %C2H4>=70
            [[40, 20, 40], 'ND'], // %C2H4 10-35, %C2H6>=30
        ];
        foreach ($cases as [$g, $zone]) {
            $r = $this->svc->evaluateGases(['h2' => 0, 'ch4' => $g[0], 'c2h4' => $g[1], 'c2h6' => $g[2], 'c2h2' => 0]);
            $this->assertSame($zone, $r['triangles']['T5']['zone'], 'T5 para CH4/C2H4/C2H6='.implode('/', $g));
        }
    }

    public function test_pentagon_centroid_matches_paper_examples(): void
    {
        // Ejemplo del paper Cheim/Duval/Haider 2020 (sección 2): H2=50, CH4=120,
        // C2H2=30, C2H4=60, C2H6=80 → centroide publicado (-7.35, -5.79).
        $r = $this->svc->evaluateGases(['h2' => 50, 'ch4' => 120, 'c2h2' => 30, 'c2h4' => 60, 'c2h6' => 80]);
        [$x, $y] = $r['pentagon']['point'];
        $this->assertEqualsWithDelta(-7.35, $x, 0.05);
        $this->assertEqualsWithDelta(-5.79, $y, 0.05);

        // Ejemplo del paper ORIGINAL Duval & Lamarre 2014 (Fig 1): H2=31, C2H6=130,
        // CH4=192, C2H4=31, C2H2=0 → centroide publicado (-17.3, -9.1).
        $r = $this->svc->evaluateGases(['h2' => 31, 'c2h6' => 130, 'ch4' => 192, 'c2h4' => 31, 'c2h2' => 0]);
        [$x, $y] = $r['pentagon']['point'];
        $this->assertEqualsWithDelta(-17.3, $x, 0.05);
        $this->assertEqualsWithDelta(-9.1, $y, 0.05);

        // Casos extremos (un solo gas al 100%): centroide degenerado = V/3.
        // Valores publicados en el paper.
        $extremes = [
            ['gas' => 'h2',   'exp' => [0.0, 33.3]],
            ['gas' => 'ch4',  'exp' => [-19.5, -26.9]],
            ['gas' => 'c2h6', 'exp' => [-31.6, 10.3]],
        ];
        foreach ($extremes as $e) {
            $gas = ['h2' => 0, 'ch4' => 0, 'c2h4' => 0, 'c2h6' => 0, 'c2h2' => 0];
            $gas[$e['gas']] = 100;
            $p = $this->svc->evaluateGases($gas)['pentagon']['point'];
            $this->assertEqualsWithDelta($e['exp'][0], $p[0], 0.15, "x {$e['gas']}");
            $this->assertEqualsWithDelta($e['exp'][1], $p[1], 0.15, "y {$e['gas']}");
        }
    }

    public function test_geometry_exposes_projected_zones(): void
    {
        $g = $this->svc->geometry();
        $this->assertArrayHasKey('T1', $g['triangles']);
        $this->assertArrayHasKey('combine', $g['pentagons']);
        // Cada zona tiene código y puntos [x,y] ya proyectados.
        $zone = $g['triangles']['T1'][0];
        $this->assertArrayHasKey('code', $zone);
        $this->assertCount(2, $zone['pts'][0]);
    }
}
