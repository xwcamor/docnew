<?php

namespace Database\Seeders;

use App\Models\OilType;
use App\Models\ResultScale;
use App\Models\Standard;
use App\Models\Test;
use App\Models\TransformerType;
use App\Models\Variable;
use Illuminate\Database\Seeder;

/**
 * Carga los catálogos base del motor de diagnóstico.
 * Idempotente: usa updateOrCreate, se puede correr varias veces sin duplicar.
 *
 * Taxonomía de aceites (familias reales de trafo):
 *   - mineral / silicona: motor con reglas propias.
 *   - éster natural: soya y girasol son la misma familia pero con umbrales
 *     DISTINTOS (girasol mucho más estricto), por eso se mantienen como códigos
 *     separados — colapsarlos borraría 32 de 36 escalones de reglas.
 *   - éster sintético: catálogo listo, reglas a portar (futuro).
 *   - ninguno ("-"): equipo sin aceite (reactores secos, etc.); no tiene reglas,
 *     no entra a cromas/fiquis. Replica el aceite "-" del sistema viejo.
 * Los CÓDIGOS no cambian nunca (las reglas se atan al code, no al nombre).
 */
class DiagnosticCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Tipos de aceite ----
        foreach ([
            ['code' => 'mineral',         'name' => 'Mineral',                 'sort_order' => 1],
            ['code' => 'silicona',        'name' => 'Silicona',                'sort_order' => 2],
            ['code' => 'vegetal_soya',    'name' => 'Éster Natural (Soya)',    'sort_order' => 3],
            ['code' => 'vegetal_girasol', 'name' => 'Éster Natural (Girasol)', 'sort_order' => 4],
            ['code' => 'ester_sintetico', 'name' => 'Éster Sintético',         'sort_order' => 5],
            ['code' => 'ninguno',         'name' => 'Sin aceite (-)',          'sort_order' => 6],
        ] as $row) {
            OilType::updateOrCreate(['code' => $row['code']], $row);
        }

        // ---- Tipos de transformador ----
        foreach ([
            ['code' => 'potencia',     'name' => 'Potencia',     'sort_order' => 1, 'shape' => 'tank'],
            ['code' => 'distribucion', 'name' => 'Distribución', 'sort_order' => 2, 'shape' => 'pole'],
            ['code' => 'horno',        'name' => 'Horno',        'sort_order' => 3, 'shape' => 'tank'],
        ] as $row) {
            TransformerType::updateOrCreate(['code' => $row['code']], $row);
        }

        // ---- Normas ----
        foreach ([
            ['code' => 'iec_60599',   'name' => 'IEC 60599',   'version' => '2015'],
            ['code' => 'ieee_c57_104','name' => 'IEEE C57.104', 'version' => '2019'],
        ] as $row) {
            Standard::updateOrCreate(['code' => $row['code']], $row);
        }

        // ---- Pruebas (con peso para el Índice de Salud) ----
        // Las 4 pruebas entran al HI por default (fpot incluido, por decisión del
        // usuario). El peso es dinámico: una prueba sin muestra no castiga el HI.
        foreach ([
            // Pesos = criterios K de la metodología Hitachi (Health_Index.pdf,
            // tabla "Formulación del índice de salud"): DGA 10 · Oil Quality 8 ·
            // Furan 6 · Power Factor 10. Alineados 2026-07-16 (antes 10/6/5/5,
            // sin fuente). El peso dinámico renormaliza cuando faltan pruebas.
            ['code' => 'cromas',  'name' => 'Cromatografía (DGA)', 'hi_weight' => 10, 'hi_enabled' => true,  'sort_order' => 1],
            ['code' => 'fiquis',  'name' => 'Fisicoquímico',       'hi_weight' => 8,  'hi_enabled' => true,  'sort_order' => 2],
            ['code' => 'furanos', 'name' => 'Furanos',             'hi_weight' => 6,  'hi_enabled' => true,  'sort_order' => 3],
            ['code' => 'fpot',    'name' => 'Factor de Potencia',  'hi_weight' => 10, 'hi_enabled' => true,  'sort_order' => 4],
        ] as $row) {
            Test::updateOrCreate(['code' => $row['code']], $row);
        }

        // ---- Variables (los 9 gases de cromatografía) ----
        $gases = [
            ['code' => 'h2',   'name' => 'Hidrógeno (H2)'],
            ['code' => 'o2',   'name' => 'Oxígeno (O2)'],
            ['code' => 'n2',   'name' => 'Nitrógeno (N2)'],
            ['code' => 'ch4',  'name' => 'Metano (CH4)'],
            ['code' => 'co',   'name' => 'Monóxido de carbono (CO)'],
            ['code' => 'co2',  'name' => 'Dióxido de carbono (CO2)'],
            ['code' => 'c2h4', 'name' => 'Etileno (C2H4)'],
            ['code' => 'c2h6', 'name' => 'Etano (C2H6)'],
            ['code' => 'c2h2', 'name' => 'Acetileno (C2H2)'],
        ];
        foreach ($gases as $i => $row) {
            Variable::updateOrCreate(
                ['code' => $row['code']],
                $row + ['unit' => 'ppm', 'kind' => 'measure', 'sort_order' => $i + 1]
            );
        }

        // ---- Escala de resultado (semáforo de cromatografía) ----
        // Del código real: <1.2 Muy Bueno (40) … >=3 Muy Malo (0).
        // hi_rating es el 4..0 que alimenta el Índice de Salud.
        $cromas = Test::where('code', 'cromas')->first();
        $scale = [
            ['from' => null, 'to' => 1.2, 'label' => 'Muy Bueno', 'color' => 'green',  'rating' => 4],
            ['from' => 1.2,  'to' => 1.5, 'label' => 'Bueno',     'color' => 'lime',   'rating' => 3],
            ['from' => 1.5,  'to' => 2.0, 'label' => 'Medio',     'color' => 'yellow', 'rating' => 2],
            ['from' => 2.0,  'to' => 3.0, 'label' => 'Malo',      'color' => 'orange', 'rating' => 1],
            ['from' => 3.0,  'to' => null,'label' => 'Muy Malo',  'color' => 'red',    'rating' => 0],
        ];
        foreach ($scale as $i => $s) {
            ResultScale::updateOrCreate(
                ['test_id' => $cromas->id, 'sort_order' => $i + 1],
                [
                    'score_from' => $s['from'], 'score_to' => $s['to'],
                    'condition_label' => $s['label'], 'color' => $s['color'],
                    'hi_rating' => $s['rating'],
                ]
            );
        }

        // ---- Escala del Índice de Salud (semáforo global del HI, 0-100) ----
        // test_id NULL = escala global (no pertenece a una prueba puntual).
        // Tope INCLUSIVO como el sistema viejo: <=30 Muy Malo … >85 Muy Bueno.
        // El HealthIndexService la resuelve (no usa ResultScale::contains()).
        $hiScale = [
            ['from' => null, 'to' => 30,  'label' => 'Muy Malo',  'color' => 'red',    'rating' => 0],
            ['from' => 30,   'to' => 50,  'label' => 'Malo',      'color' => 'orange', 'rating' => 1],
            ['from' => 50,   'to' => 70,  'label' => 'Medio',     'color' => 'yellow', 'rating' => 2],
            ['from' => 70,   'to' => 85,  'label' => 'Bueno',     'color' => 'lime',   'rating' => 3],
            ['from' => 85,   'to' => null,'label' => 'Muy Bueno', 'color' => 'green',  'rating' => 4],
        ];
        foreach ($hiScale as $i => $s) {
            ResultScale::updateOrCreate(
                ['test_id' => null, 'sort_order' => $i + 1],
                [
                    'score_from' => $s['from'], 'score_to' => $s['to'],
                    'condition_label' => $s['label'], 'color' => $s['color'],
                    'hi_rating' => $s['rating'],
                ]
            );
        }

        // ---- Escala de furanos (semáforo por 2-furfuraldehído en ppm) ----
        // DECISIÓN (2026-06): semáforo CONSERVADOR del sistema viejo (furano.rb /
        // Excel "Furano"), NO las bandas Chendong que se probaron primero. Razón:
        // la empresa certificó diagnósticos por años con este criterio; Chendong
        // resultó 1-2 bandas más indulgente (a 1 ppm: viejo=Muy Malo, Chendong=
        // Medio) y suavizaba 16 trafos críticos reales. Sub-diagnosticar es el
        // riesgo inaceptable. El DP estimado SÍ se calcula con Chendong (CIGRE
        // TB 494 / IEEE C57.91) como dato informativo en la UI/reportes — solo
        // el VEREDICTO usa las bandas históricas. Umbrales editables (datos).
        $furanos = Test::where('code', 'furanos')->first();
        if ($furanos) {
            $furScale = [
                ['from' => null, 'to' => 0.1,  'label' => 'Muy Bueno', 'color' => 'green',  'rating' => 4],
                ['from' => 0.1,  'to' => 0.25, 'label' => 'Bueno',     'color' => 'lime',   'rating' => 3],
                ['from' => 0.25, 'to' => 0.5,  'label' => 'Medio',     'color' => 'yellow', 'rating' => 2],
                ['from' => 0.5,  'to' => 1.0,  'label' => 'Malo',      'color' => 'orange', 'rating' => 1],
                ['from' => 1.0,  'to' => null, 'label' => 'Muy Malo',  'color' => 'red',    'rating' => 0],
            ];
            foreach ($furScale as $i => $s) {
                ResultScale::updateOrCreate(
                    ['test_id' => $furanos->id, 'sort_order' => $i + 1],
                    [
                        'score_from' => $s['from'], 'score_to' => $s['to'],
                        'condition_label' => $s['label'], 'color' => $s['color'],
                        'hi_rating' => $s['rating'],
                    ]
                );
            }
        }

        // ---- Escala de Factor de Potencia (semáforo por num_fac, en %) ----
        // Del código viejo (factor.rb): <0.5 Muy Bueno (4) · 0.5-0.7 Bueno (3) ·
        // 0.7-1.0 Medio (2) · 1.0-2.0 Malo (1) · ≥2.0 Muy Malo (0). Umbrales
        // editables. fpot entra al HI (hi_enabled=true). OJO: como es prueba
        // esperada, todo trafo sin muestra de fpot queda marcado "parcial"
        // (el valor del HI no cambia por el peso dinámico).
        $fpot = Test::where('code', 'fpot')->first();
        if ($fpot) {
            $fpotScale = [
                ['from' => null, 'to' => 0.5, 'label' => 'Muy Bueno', 'color' => 'green',  'rating' => 4],
                ['from' => 0.5,  'to' => 0.7, 'label' => 'Bueno',     'color' => 'lime',   'rating' => 3],
                ['from' => 0.7,  'to' => 1.0, 'label' => 'Medio',     'color' => 'yellow', 'rating' => 2],
                ['from' => 1.0,  'to' => 2.0, 'label' => 'Malo',      'color' => 'orange', 'rating' => 1],
                ['from' => 2.0,  'to' => null,'label' => 'Muy Malo',  'color' => 'red',    'rating' => 0],
            ];
            foreach ($fpotScale as $i => $s) {
                ResultScale::updateOrCreate(
                    ['test_id' => $fpot->id, 'sort_order' => $i + 1],
                    [
                        'score_from' => $s['from'], 'score_to' => $s['to'],
                        'condition_label' => $s['label'], 'color' => $s['color'],
                        'hi_rating' => $s['rating'],
                    ]
                );
            }
        }

        // ---- Escala de fisicoquímico (semáforo por DGAF, igual que el JSON) ----
        // Se mueve a result_scales para que sea editable en la UI de reglas; el
        // servicio la lee de aquí y cae al JSON solo si no existe.
        $fiquis = Test::where('code', 'fiquis')->first();
        if ($fiquis) {
            $fiquisScale = [
                ['from' => null, 'to' => 1.2, 'label' => 'Muy Bueno', 'color' => 'green',  'rating' => 4],
                ['from' => 1.2,  'to' => 1.5, 'label' => 'Bueno',     'color' => 'lime',   'rating' => 3],
                ['from' => 1.5,  'to' => 2.0, 'label' => 'Medio',     'color' => 'yellow', 'rating' => 2],
                ['from' => 2.0,  'to' => 3.0, 'label' => 'Malo',      'color' => 'orange', 'rating' => 1],
                ['from' => 3.0,  'to' => null,'label' => 'Muy Malo',  'color' => 'red',    'rating' => 0],
            ];
            foreach ($fiquisScale as $i => $s) {
                ResultScale::updateOrCreate(
                    ['test_id' => $fiquis->id, 'sort_order' => $i + 1],
                    [
                        'score_from' => $s['from'], 'score_to' => $s['to'],
                        'condition_label' => $s['label'], 'color' => $s['color'],
                        'hi_rating' => $s['rating'],
                    ]
                );
            }
        }

        $this->command->info('Catálogos de diagnóstico cargados (aceites, trafos, normas, pruebas, gases, semáforo cromas + HI + furanos + fpot + fiquis).');
    }
}
