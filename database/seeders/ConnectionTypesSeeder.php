<?php

namespace Database\Seeders;

use App\Models\ConnectionType;
use Illuminate\Database\Seeder;

/**
 * ConnectionTypesSeeder — catálogo inicial de grupos de conexión / grupo
 * vectorial. Valores migrados del sistema viejo. Idempotente (firstOrCreate
 * por code).
 */
class ConnectionTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // ── 1-16: orden HISTÓRICO. NO mover: los trafos migrados referencian
            //    estos grupos por ID (1-16) en LegacyTransformersSeeder. ──
            'Dyn5', 'Dyn11', 'Dyn1', 'Dy5', 'Dy11', 'Yd5', 'Yd11', 'Dd0',
            'Yy0', 'Dd6', 'Yy6', 'Yd1', 'Dz0', 'Yz1', 'Yz11', '-',

            // ── 17+: grupos adicionales IEC 60076 (solo se AGREGAN). El admin
            //    puede desactivar los que no use. Organizados por índice horario. ──
            'Yyn0', 'YNyn0', 'Dzn0',        // índice 0 (0°)
            'Dy1', 'YNd1', 'Yzn1',          // índice 1 (-30°)
            'YNd5', 'Yz5', 'Yzn5',          // índice 5 (-150°)
            'Dz6', 'Dzn6',                  // índice 6 (180°)
            'Dy7', 'Dyn7', 'Yd7',           // índice 7 (+150°)
            'YNd11', 'Yzn11',               // índice 11 (+30°)
            'Ii0', 'Ii6',                   // monofásicos
        ];

        foreach ($types as $i => $name) {
            ConnectionType::firstOrCreate(
                ['code' => \Illuminate\Support\Str::lower($name)],
                ['name' => $name, 'sort_order' => $i + 1, 'is_active' => true, 'created_by' => 1],
            );
        }
    }
}
