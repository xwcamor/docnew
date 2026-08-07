<?php

namespace Database\Seeders;

use App\Models\TapChangerType;
use Illuminate\Database\Seeder;

/**
 * TapChangerTypesSeeder — catálogo inicial de tipos de conmutador (editable).
 *
 * OLTC (on-load tap changer) = bajo carga; DETC (de-energized tap changer) =
 * conmutación en vacío; sin conmutador. Idempotente (firstOrCreate por code).
 *
 * El ORDEN replica el catálogo del sistema viejo (conmutation_types):
 *   1=OLTC, 2=DETC, 3="-". Así el import legacy es un mapeo 1:1 por id.
 */
class TapChangerTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'oltc',  'name' => 'OLTC'],
            ['code' => 'detc',  'name' => 'DETC'],
            ['code' => 'none',  'name' => '-'],
        ];

        foreach ($types as $i => $t) {
            TapChangerType::firstOrCreate(
                ['code' => $t['code']],
                ['name' => $t['name'], 'sort_order' => $i + 1, 'is_active' => true, 'created_by' => 1],
            );
        }
    }
}
