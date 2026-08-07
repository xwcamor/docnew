<?php

namespace Database\Seeders;

use App\Models\Chromatographical;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use Illuminate\Database\Seeder;

/**
 * Crea un transformador de DEMO con una muestra real para probar el motor.
 *
 * Los valores son los del transformador L243929B (NUTRIEN) de la captura del
 * sistema viejo: aceite mineral, y los gases visibles en la gráfica.
 * Sirve para verificar que el motor nuevo da un diagnóstico coherente.
 *
 * Uso:
 *   php artisan db:seed --class=DemoTransformerSeeder
 *   php artisan diagnose:cromas {id_que_imprime}
 */
class DemoTransformerSeeder extends Seeder
{
    public function run(): void
    {
        $mineral  = OilType::where('code', 'mineral')->first();
        $potencia = TransformerType::where('code', 'potencia')->first();

        $t = Transformer::updateOrCreate(
            ['serial' => 'L243929B-DEMO'],
            [
                'tag'                 => 'TR01-DEMO',
                'oil_type_id'         => $mineral?->id,
                'transformer_type_id' => $potencia?->id,
                'voltage_kv'          => 12.0,
                'power_mva'           => 3.0,
                'manufacture_year'    => 1980,
            ]
        );

        // Muestra con valores realistas (gases bajos = transformador sano).
        $sample = Chromatographical::updateOrCreate(
            ['transformer_id' => $t->id, 'sample_date' => '2024-09-04'],
            [
                'h2'   => 4,
                'o2'   => 1247,
                'n2'   => 55957,
                'ch4'  => 57,
                'co'   => 1075,
                'co2'  => 9317,
                'c2h4' => 16,
                'c2h6' => 15,
                'c2h2' => 1,
            ]
        );

        $this->command->info("Transformador demo creado. Muestra ID = {$sample->id}");
        $this->command->info("Prueba con:  php artisan diagnose:cromas {$sample->id}");
    }
}
