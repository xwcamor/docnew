<?php

namespace Tests\Unit;

use App\Services\Diagnostics\FiquisDiagnosisService;
use Tests\TestCase;

class FiquisDiagnosisServiceTest extends TestCase
{
    private FiquisDiagnosisService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new FiquisDiagnosisService();
    }

    public function test_mineral_low_all_best_is_muy_bueno(): void
    {
        $r = $this->svc->evaluate('mineral', 23, ['rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05]);
        $this->assertSame('low', $r['class']);
        $this->assertSame(1.0, $r['score']);
        $this->assertSame('Muy Bueno', $r['condition']);
        $this->assertSame(4.0, $r['rating']);
    }

    public function test_mineral_low_all_worst_is_muy_malo(): void
    {
        $r = $this->svc->evaluate('mineral', 23, ['rig' => 25, 'ten' => 10, 'acid' => 0.3, 'wat' => 50, 'pot' => 2]);
        $this->assertSame(4.0, $r['score']);
        $this->assertSame('Muy Malo', $r['condition']);
        $this->assertSame(0.0, $r['rating']);
    }

    public function test_voltage_class_changes_score(): void
    {
        // rig=45: en low (≥40) → score 1; en high (>40, ≤45) → score 3.
        $low  = $this->svc->evaluate('mineral', 33,  ['rig' => 45]);
        $high = $this->svc->evaluate('mineral', 400, ['rig' => 45]);
        $this->assertSame('low', $low['class']);
        $this->assertSame(1.0, $low['score']);
        $this->assertSame('high', $high['class']);
        $this->assertSame(3.0, $high['score']);
    }

    public function test_silicona_uses_all_class_and_ignores_tension(): void
    {
        // rig=23→2, acid=0.1→1, wat=120→2, pot=0.3→2. Pesos 3/1/4/3 (sin ten).
        // dgaf = (2*3+1*1+2*4+2*3)/11 = 21/11 ≈ 1.91 → Medio.
        $r = $this->svc->evaluate('silicona', 13, ['rig' => 23, 'acid' => 0.1, 'wat' => 120, 'pot' => 0.3, 'ten' => 999]);
        $this->assertSame('all', $r['class']);
        $this->assertEqualsWithDelta(1.91, $r['score'], 0.01);
        $this->assertSame('Medio', $r['condition']);
        $this->assertArrayNotHasKey('ten', $r['components']); // silicona no puntúa tensión
    }

    public function test_dynamic_weight_with_single_param(): void
    {
        $r = $this->svc->evaluate('mineral', 33, ['rig' => 45]);
        $this->assertSame(1.0, $r['score']); // solo rig → dgaf = score de rig
    }

    public function test_acid_strict_upper_boundary(): void
    {
        // acidez = 0.20 exacto en mineral low → score 4 (borde estricto, como el Ruby).
        $r = $this->svc->evaluate('mineral', 33, ['acid' => 0.20]);
        $this->assertSame(4.0, $r['score']);
    }

    public function test_unknown_oil_or_no_params_returns_null(): void
    {
        $this->assertNull($this->svc->evaluate('ester', 33, ['rig' => 45]));
        $this->assertNull($this->svc->evaluate('mineral', 33, []));
    }

    public function test_limits_for_mineral_low_direction_and_severity(): void
    {
        $lim = $this->svc->limitsFor('mineral', 33); // clase low
        // Rigidez (más=mejor): banda baja = peor (sev 1), banda alta = mejor (sev 0).
        $this->assertCount(4, $lim['rig']);
        $this->assertSame(1.0, $lim['rig'][0]['sev']);
        $this->assertSame(0.0, $lim['rig'][3]['sev']);
        $this->assertNull($lim['rig'][3]['to']); // última banda abierta
        // Acidez (menos=mejor): banda baja = mejor (sev 0), banda alta = peor (sev 1).
        $this->assertSame(0.0, $lim['acid'][0]['sev']);
        $this->assertSame(1.0, $lim['acid'][3]['sev']);
    }

    public function test_limits_for_unknown_oil_is_empty(): void
    {
        $this->assertSame([], $this->svc->limitsFor('ester', 33));
    }

    /** pot100 y rig877 NO SUMAN al score si el principal midió (no cuentan dos veces). */
    public function test_extra_limit_params_do_not_affect_score(): void
    {
        $base = $this->svc->evaluate('mineral', 33, ['rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05]);
        $withExtra = $this->svc->evaluate('mineral', 33, [
            'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
            'rig877' => 10, 'pot100' => 99, // valores pésimos: NO deben mover el score
        ]);
        $this->assertSame($base['score'], $withExtra['score']);
        $this->assertSame('Muy Bueno', $withExtra['condition']);
    }

    // ── Sustitución: el método alterno ocupa el lugar del principal ───────────
    //
    // El alterno se puntúa con las mismas tres bandas con las que ya se colorea
    // su celda: cumple → 1, dentro de la tolerancia (±10 %) → 3, fuera de norma
    // → 4. Mineral: D877 límite 25 → verde ≥27.5, ámbar 25–27.5, rojo <25.
    // Factor a 100 °C límite 5 → verde <4.5, ámbar 4.5–5, rojo ≥5.

    /** Sin D1816 pero con D877, la rigidez SÍ entra al promedio. */
    public function test_alternate_method_substitutes_when_principal_missing(): void
    {
        $r = $this->svc->evaluate('mineral', 33, ['rig877' => 40]);

        $this->assertNotNull($r);
        $this->assertSame(1.0, $r['score']);                       // 40 ≥ 33.33 → score 1
        $this->assertArrayHasKey('rig877', $r['components']);
        $this->assertArrayNotHasKey('rig', $r['components']);
        $this->assertSame('rig', $r['components']['rig877']['substitutes']);
        $this->assertSame(3.0, $r['components']['rig877']['weight']); // el peso es de la PROPIEDAD
    }

    /** Y si el alterno viene mal, la propiedad castiga: no se puede esconder. */
    public function test_alternate_method_out_of_spec_is_penalised(): void
    {
        $r = $this->svc->evaluate('mineral', 33, ['rig877' => 20]);   // < 25, fuera de norma
        $this->assertSame(4.0, $r['score']);
        $this->assertSame('Muy Malo', $r['condition']);

        // Antes de la sustitución esta muestra salía sin rigidez en el promedio:
        // con el resto perfecto daba "Muy Bueno" y ocultaba un aceite peligroso.
        $completo = $this->svc->evaluate('mineral', 33, [
            'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05, 'rig877' => 20,
        ]);
        $this->assertGreaterThan(1.0, $completo['score']);
        $this->assertNotSame('Muy Bueno', $completo['condition']);
    }

    /** El alterno se juzga contra SU norma, nunca contra la del principal. */
    public function test_alternate_scores_against_its_own_standard(): void
    {
        // 26 kV pasa el mínimo del D877 (25) pero estaría fuera de norma en
        // D1816 (30). Con su propia norma es "pegado al límite" (3), no 4.
        $this->assertSame(3.0, $this->svc->evaluate('mineral', 33, ['rig877' => 26])['score']);

        // Factor a 100 °C: 0.3 % es bueno para su límite de 5 %, aunque contra
        // el de 25 °C (0.1) sería mediocre.
        $this->assertSame(1.0, $this->svc->evaluate('mineral', 33, ['pot100' => 0.3])['score']);
        $this->assertSame(4.0, $this->svc->evaluate('mineral', 33, ['pot100' => 6])['score']);
    }

    /** La traza dice con qué método se puntuó y no lo repite como "no puntúa". */
    public function test_explain_marks_the_substituted_method(): void
    {
        $e = $this->svc->explain('mineral', 33, ['rig877' => 40, 'pot' => 0.05]);
        $filas = collect($e['params'])->keyBy('param');

        $this->assertSame('rig877', $filas['rig']['measured_as']);
        $this->assertSame(1, $filas['rig']['score']);
        $this->assertSame([25], $filas['rig']['thresholds']);
        $this->assertNull($filas['pot']['measured_as']);

        // rig877 ya puntúa arriba: no puede volver a salir en "Parámetros de
        // referencia" marcado como que no puntúa.
        $referencia = collect($e['params'])->where('scores', false)->pluck('param');
        $this->assertNotContains('rig877', $referencia);
    }

    /**
     * El bloque "Parámetros de referencia" solo lista los métodos alternos que
     * SE MIDIERON. Una fila "No medido · no puntúa" no dice nada del
     * diagnóstico y solo alarga la traza.
     */
    public function test_reference_block_only_lists_measured_methods(): void
    {
        $soloUno = $this->svc->explain('mineral', 33, ['rig' => 45, 'pot' => 0.05, 'pot100' => 3]);
        $ref = collect($soloUno['params'])->where('scores', false)->pluck('param');
        $this->assertContains('pot100', $ref);
        $this->assertNotContains('rig877', $ref);

        // Ninguno medido → el bloque entero desaparece.
        $ninguno = $this->svc->explain('mineral', 33, ['rig' => 45, 'pot' => 0.05]);
        $this->assertCount(0, collect($ninguno['params'])->where('scores', false));
    }

    /** Sin ninguno de los dos métodos, la propiedad simplemente no participa. */
    public function test_no_method_at_all_keeps_dynamic_weight(): void
    {
        $r = $this->svc->evaluate('mineral', 33, ['ten' => 30]);
        $this->assertSame(['ten'], array_keys($r['components']));
    }

    /** pot100/rig877 tienen color propio por su norma (umbral único + ±10%). */
    public function test_limit_params_color_by_own_threshold(): void
    {
        $lim = $this->svc->limitsFor('mineral', 33);
        $this->assertCount(3, $lim['rig877']);          // 3 bandas (no 4)
        $this->assertSame(1.0, $lim['rig877'][0]['sev']); // <25 → rojo (más=mejor)
        $this->assertSame(0.0, $lim['rig877'][2]['sev']); // ≥27.5 → verde
        $this->assertSame(0.0, $lim['pot100'][0]['sev']); // <4.5 → verde (menos=mejor)
        $this->assertSame(1.0, $lim['pot100'][2]['sev']); // ≥5 → rojo
    }

    /** columnsFor entrega columnas con su norma; sin aceite, igual entrega (fallback). */
    public function test_columns_for_includes_astm_and_falls_back_without_oil(): void
    {
        $cols = collect($this->svc->columnsFor('mineral', 33))->keyBy('key');
        $this->assertSame('D877', $cols['rig877']['astm']);
        $this->assertSame('limit', $cols['rig877']['mode']);
        $this->assertSame('scored', $cols['rig']['mode']);
        $this->assertNotEmpty($this->svc->columnsFor(null, null));
    }

    /**
     * Si el super carga los tres umbrales reales del método alterno, el alterno
     * pasa a graduar 1..4 como cualquier otro parámetro, en vez de las tres
     * bandas del límite de aceptación.
     */
    public function test_loaded_thresholds_win_over_the_single_limit(): void
    {
        $reglas = json_decode(file_get_contents(database_path('seeders/data/fiquis_rules.json')), true);
        $reglas['tables']['mineral']['low']['rig877'] = [45, 38, 30];   // gradación cargada a mano
        $svc = new FiquisDiagnosisService($reglas);

        // 40 kV: con el límite pelado (≥27.5 cumple) sería score 1; con estos, 2.
        $this->assertSame(2.0, $svc->evaluate('mineral', 33, ['rig877' => 40])['score']);

        // Y la celda pasa a colorearse con 4 bandas, no con el límite pelado.
        $this->assertCount(4, $svc->limitsFor('mineral', 33)['rig877']);
    }

    /** Media gradación (t3 sin t2) se ignora: vuelve al límite único. */
    public function test_half_filled_gradation_falls_back_to_the_single_limit(): void
    {
        $reglas = json_decode(file_get_contents(database_path('seeders/data/fiquis_rules.json')), true);
        $reglas['tables']['mineral']['low']['rig877'] = [25, 30];   // incompleta
        $svc = new FiquisDiagnosisService($reglas);

        // Se puntúa igual que con [25]: 40 ≥ 27.5 → cumple → score 1.
        $this->assertSame(1.0, $svc->evaluate('mineral', 33, ['rig877' => 40])['score']);
    }

    /**
     * La razón de ser del criterio: el color de la celda y el score del alterno
     * NUNCA pueden decir cosas distintas. Verde aporta 1, ámbar 3 y rojo 4.
     */
    public function test_alternate_score_always_agrees_with_the_cell_colour(): void
    {
        $bandas = $this->svc->limitsFor('mineral', 33);
        // OJO: no un array — PHP castea las claves float a int y 0.0/0.5 colisionan.
        $sevAScore = fn (float $sev) => match (true) {
            $sev <= 0.0 => 1.0,   // verde  → cumple
            $sev >= 1.0 => 4.0,   // rojo   → fuera de norma
            default     => 3.0,   // ámbar  → pegado al límite
        };

        foreach (['rig877' => 'higher', 'pot100' => 'lower'] as $alt => $_) {
            foreach ($bandas[$alt] as $b) {
                // Un valor dentro de la banda: justo encima del piso, o un poco
                // por debajo del techo si la banda es la última (sin techo).
                $v = $b['to'] === null ? $b['from'] * 1.5 : ($b['from'] + $b['to']) / 2;
                $r = $this->svc->evaluate('mineral', 33, [$alt => $v]);

                $this->assertSame(
                    $sevAScore($b['sev']),
                    $r['score'],
                    "$alt = $v cae en una banda de severidad {$b['sev']} pero puntúa {$r['score']}",
                );
            }
        }
    }
}
