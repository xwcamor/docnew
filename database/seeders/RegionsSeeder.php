<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RegionsSeeder — catálogo geográfico maestro (10 regiones reales).
 *
 * Idempotente: usa updateOrInsert por id, se puede re-correr sin duplicar.
 *
 * Para data de prueba en bulk (benchmarking), ver el bloque comentado al final.
 */
class RegionsSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            ['id' => 1,  'name' => 'América del Sur'],
            ['id' => 2,  'name' => 'América del Norte'],
            ['id' => 3,  'name' => 'América Central y el Caribe'],
            ['id' => 4,  'name' => 'Europa'],
            ['id' => 5,  'name' => 'Asia'],
            ['id' => 6,  'name' => 'África'],
            ['id' => 7,  'name' => 'Oceanía'],
            ['id' => 8,  'name' => 'Antártida'],
            ['id' => 9,  'name' => 'Medio Oriente'],
            ['id' => 10, 'name' => 'Sudeste Asiático'],
        ];

        foreach ($regions as $r) {
            DB::table('regions')->updateOrInsert(
                ['id' => $r['id']],
                [
                    'slug'       => Str::random(22),
                    'name'       => $r['name'],
                    'is_active'  => true,
                    'created_by' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Postgres: sincroniza la secuencia para que los inserts futuros
        // continúen después del id más alto forzado por este seeder.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('regions_id_seq', COALESCE((SELECT MAX(id) FROM regions), 0) + 1, false)");
        }

        $this->command?->info('Regions seeded: ' . count($regions) . ' real geographic regions.');

        // ─────────────────────────────────────────────────────────────────────
        // BULK DE PRUEBA: 1000 regiones fake para benchmarking de queries
        // y paginación. NO correr en producción.
        //
        // Para activar: descomentar el bloque, ejecutar:
        //   php artisan db:seed --class=Database\\Seeders\\RegionsSeeder
        // Re-comentar después de generar.
        // ─────────────────────────────────────────────────────────────────────
        //
        // $bases = [
        //     'Europa', 'América', 'Asia', 'África', 'Oceanía',
        //     'Norte', 'Sur', 'Centro', 'Este', 'Oeste',
        //     'Andina', 'Caribe', 'Pacífico', 'Atlántico', 'Mediterránea',
        //     'Septentrional', 'Meridional', 'Oriental', 'Occidental', 'Costera',
        //     'Continental', 'Insular', 'Tropical', 'Templada', 'Polar',
        //     'Amazónica', 'Patagónica', 'Alpina', 'Balcánica', 'Escandinava',
        //     'Báltica', 'Ibérica', 'Anatolia', 'Indochina', 'Subsahariana',
        //     'Magreb', 'Levante', 'Pampa', 'Sabana', 'Selva',
        // ];
        // $sectors = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
        // $rows = [];
        // $now  = now();
        // $used = [];
        // for ($i = 1; $i <= 1000; $i++) {
        //     $base   = $bases[array_rand($bases)];
        //     $sector = $sectors[array_rand($sectors)];
        //     $name   = "{$base} {$sector} - {$i}";
        //     if (isset($used[$name])) continue;
        //     $used[$name] = true;
        //     $rows[] = [
        //         'slug'       => Str::random(22),
        //         'name'       => $name,
        //         'is_active'  => fake()->boolean(80),
        //         'created_at' => $now->copy()->subDays(rand(0, 365))->subHours(rand(0, 23)),
        //         'updated_at' => $now,
        //     ];
        //     if (count($rows) >= 200) {
        //         DB::table('regions')->insert($rows);
        //         $rows = [];
        //     }
        // }
        // if (count($rows) > 0) DB::table('regions')->insert($rows);
        // if (DB::getDriverName() === 'pgsql') {
        //     DB::statement("SELECT setval('regions_id_seq', COALESCE((SELECT MAX(id) FROM regions), 0) + 1, false)");
        // }
        // $this->command?->info('1000 fake test regions seeded.');
    }
}
