<?php

namespace Database\Seeders;

use App\Models\DiagnosticDataset;
use Illuminate\Database\Seeder;

/**
 * DiagnosticDatasetsSeeder — pasa los datos editables de diagnóstico (que vivían
 * en archivos JSON) a la tabla diagnostic_datasets, para que se editen desde la UI.
 *
 * firstOrCreate (NO updateOrCreate): siembra desde el archivo solo si la fila no
 * existe. Re-correr setup:project NO pisa las ediciones hechas en la UI. Para
 * resetear a los valores de norma se borra la fila (o el botón "restaurar" del editor).
 */
class DiagnosticDatasetsSeeder extends Seeder
{
    public function run(): void
    {
        $sets = [
            ['key' => 'fiquis_rules',        'label' => 'Fisicoquímico — IEEE C57.106'],
            ['key' => 'diagnostic_colors',   'label' => 'Colores del diagnóstico'],
        ];

        foreach ($sets as $s) {
            $path = database_path("seeders/data/{$s['key']}.json");
            if (!is_file($path)) {
                continue;
            }
            $data = json_decode(file_get_contents($path), true) ?? [];
            DiagnosticDataset::firstOrCreate(
                ['key' => $s['key']],
                ['label' => $s['label'], 'data' => $data, 'created_by' => 1],
            );
        }

        $this->command?->info('Diagnostic datasets seeded (fiquis_rules, diagnostic_colors).');
    }
}
