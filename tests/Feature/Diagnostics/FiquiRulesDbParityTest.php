<?php

namespace Tests\Feature\Diagnostics;

use App\Services\Diagnostics\FiquisDiagnosisService;
use Database\Seeders\FiquiRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-verificación de la migración de reglas de fiquis (JSON → tablas):
 * la estructura reconstruida desde la BD debe ser IDÉNTICA al archivo de
 * fábrica, y el diagnóstico (DGAF) debe dar igual antes y después de mover los
 * datos a BD. Es la red de seguridad del refactor de un motor ya auditado.
 */
class FiquiRulesDbParityTest extends TestCase
{
    use RefreshDatabase;

    private function jsonRules(): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/fiquis_rules.json')), true);
    }

    public function test_db_tables_reproduce_the_json_thresholds_exactly(): void
    {
        (new FiquiRulesSeeder())->run();

        $json = $this->jsonRules();
        $db   = FiquisDiagnosisService::loadFromDb();

        // Umbrales por aceite/clase/parámetro — idénticos (comparación numérica).
        foreach ($json['tables'] as $oil => $classes) {
            foreach ($classes as $cls => $byParam) {
                foreach ($byParam as $key => $vals) {
                    $expected = array_map('floatval', (array) $vals);
                    $actual   = array_map('floatval', (array) ($db['tables'][$oil][$cls][$key] ?? []));
                    $this->assertSame($expected, $actual, "umbral distinto en $oil/$cls/$key");
                }
            }
        }

        // Pesos y dirección de cada parámetro puntuado.
        foreach ($json['param_order'] as $key) {
            $this->assertSame(
                (float) $json['params'][$key]['weight'],
                (float) $db['params'][$key]['weight'],
                "peso distinto en $key",
            );
            $this->assertSame($json['params'][$key]['dir'], $db['params'][$key]['dir']);
        }

        // Mismos parámetros puntúan (el orden no afecta el DGAF: la suma es conmutativa).
        $expected = $json['param_order']; sort($expected);
        $actual   = $db['param_order'];   sort($actual);
        $this->assertSame($expected, $actual, 'conjunto de parámetros puntuados distinto');
    }

    public function test_diagnosis_score_is_identical_db_vs_file(): void
    {
        (new FiquiRulesSeeder())->run();

        $fromFile = new FiquisDiagnosisService($this->jsonRules()); // usa el JSON
        $fromDb   = new FiquisDiagnosisService();                   // usa la BD

        $cases = [
            ['mineral', 30,  ['rig' => 50, 'ten' => 28, 'acid' => 0.03, 'wat' => 15, 'pot' => 0.08]],
            ['mineral', 150, ['rig' => 45, 'ten' => 22, 'acid' => 0.12, 'wat' => 28, 'pot' => 0.4]],
            ['mineral', 400, ['rig' => 38, 'ten' => 18, 'acid' => 0.2, 'wat' => 45, 'pot' => 1.2]],
            ['silicona', 50, ['rig' => 24, 'acid' => 0.5, 'wat' => 120, 'pot' => 0.5]],
            ['vegetal_soya', 30, ['rig' => 42, 'ten' => 9, 'acid' => 0.8, 'wat' => 480, 'pot' => 3.2]],
            ['vegetal_girasol', 30, ['rig' => 33, 'ten' => 7, 'acid' => 1.2, 'wat' => 520, 'pot' => 3.8]],
        ];

        foreach ($cases as [$oil, $kv, $vals]) {
            $a = $fromFile->evaluate($oil, $kv, $vals);
            $b = $fromDb->evaluate($oil, $kv, $vals);
            $this->assertSame($a['score'], $b['score'], "DGAF distinto en $oil/$kv");
        }
    }
}
