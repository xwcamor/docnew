<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BrandsSeeder — catálogo real de marcas/fabricantes del sistema viejo (251).
 *
 * Catálogo PER-TENANT (empresa 1): cada workspace tiene su propio catálogo. Aquí
 * se siembra el del workspace demo (tenant_id = 1).
 *
 * - brands.csv (id, name, code, deleted): se inserta con el ID original para
 *   mapear los transformadores del viejo (brand_id) en la migración de datos.
 * - deleted=1 → se inserta como soft-delete (deleted_at), queda en papelera.
 * Idempotente: salta marcas ya existentes por id.
 */
class BrandsSeeder extends Seeder
{
    private const TENANT_ID = 1;

    public function run(): void
    {
        $path = database_path('seeders/data/brands.csv');
        if (!is_file($path)) {
            $this->command?->warn('brands.csv no encontrado; se omite.');
            return;
        }

        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        array_shift($rows); // header

        $now = now();
        $created = 0; $trashed = 0;

        DB::transaction(function () use ($rows, $now, &$created, &$trashed) {
            foreach ($rows as $i => $row) {
                [$id, $name, $code, $deleted] = array_pad($row, 4, null);
                $id = (int) $id;
                $name = trim((string) $name);
                $code = trim((string) $code) ?: null;
                $isDeleted = (string) $deleted === '1';

                if ($id <= 0 || $name === '') {
                    continue;
                }
                if (DB::table('brands')->where('id', $id)->exists()) {
                    continue; // idempotente
                }

                DB::table('brands')->insert([
                    'id'                  => $id,
                    'slug'                => Str::random(22),
                    'tenant_id'           => self::TENANT_ID,
                    'name'                => $name,
                    'code'                => $code,
                    'sort_order'          => $i + 1,
                    'is_active'           => !$isDeleted,
                    'created_by'          => 1,
                    'deleted_by'          => $isDeleted ? 1 : null,
                    'deleted_description' => $isDeleted ? 'Migrado como borrado del sistema viejo.' : null,
                    'deleted_at'          => $isDeleted ? $now : null,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
                $isDeleted ? $trashed++ : $created++;
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('brands_id_seq', COALESCE((SELECT MAX(id) FROM brands), 0) + 1, false)");
        }

        $this->command?->info("Brands seeded: {$created} activas + {$trashed} en papelera (catálogo tenant 1).");
    }
}
