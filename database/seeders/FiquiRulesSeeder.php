<?php

namespace Database\Seeders;

use App\Models\FiquiParam;
use App\Models\FiquiThreshold;
use Illuminate\Database\Seeder;

/**
 * FiquiRulesSeeder — pasa las reglas de fisicoquímico del archivo de fábrica
 * (database/seeders/data/fiquis_rules.json) a las tablas relacionales
 * fiqui_params + fiqui_thresholds. El JSON queda solo como semilla.
 *
 * firstOrCreate (NO updateOrCreate): re-sembrar NO pisa ediciones hechas en la
 * UI. Para resetear un valor de norma se edita/borra la fila.
 */
class FiquiRulesSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/fiquis_rules.json');
        if (!is_file($path)) {
            return;
        }
        $json = json_decode(file_get_contents($path), true) ?? [];

        $params     = $json['params'] ?? [];
        $paramOrder = $json['param_order'] ?? array_keys($params);
        $display    = $json['display'] ?? array_keys($params);

        foreach ($display as $i => $key) {
            $meta = $params[$key] ?? [];

            // IDENTIDAD del parámetro (qué es y cómo se mide): se refresca
            // siempre desde el JSON. Son datos de norma, no configuración: si
            // se corrige el método o la condición de ensayo, re-sembrar debe
            // propagarlo. Mismo criterio que SettingsSeeder.
            $identidad = [
                'dir'           => $meta['dir'] ?? 'lower',
                'unit'          => $meta['unit'] ?? null,
                'astm'          => $meta['astm'] ?? null,
                'mode'          => $meta['mode'] ?? 'scored',
                'scored'        => in_array($key, $paramOrder, true),
                'display_order' => $i + 1,
                'is_core'       => true,
            ];

            // CALIBRACIÓN (peso en el DGAF y tolerancia): el super la edita
            // desde el editor de reglas → NUNCA se pisa si la fila ya existe.
            $calibracion = [
                'weight' => $meta['weight'] ?? null,
                'tol'    => $meta['tol'] ?? null,
            ];

            $fila = FiquiParam::firstWhere('key', $key);
            if ($fila) {
                $fila->update($identidad);
                continue;
            }

            FiquiParam::create(['key' => $key, 'is_active' => true, 'created_by' => 1]
                + $identidad + $calibracion);
        }

        foreach (($json['tables'] ?? []) as $oil => $classes) {
            foreach ($classes as $class => $byParam) {
                foreach ($byParam as $key => $vals) {
                    $vals = array_values((array) $vals);
                    FiquiThreshold::firstOrCreate(
                        ['param_key' => $key, 'oil_code' => $oil, 'voltage_class' => $class],
                        [
                            't1' => $vals[0] ?? null,
                            't2' => $vals[1] ?? null,
                            't3' => $vals[2] ?? null,
                        ],
                    );
                }
            }
        }
    }
}
