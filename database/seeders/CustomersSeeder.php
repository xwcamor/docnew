<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CustomersSeeder — jerarquía COMPLETA y FIEL del sistema viejo (sin inventar
 * filas): Cliente → Ubicación → Área → Subestación.
 *
 * Datos (CSV editables, dump completo del viejo, con flag `deleted`):
 * - customers.csv (id, name, cod, country_iso, address, deleted): 344 clientes.
 * - customer_locations.csv (id, customer_id, name, deleted): 843 ubicaciones.
 * - customer_areas.csv (id, customer_location_id, name, deleted): 1940 áreas.
 * - customer_substations.csv (id, customer_area_id, name, deleted): 1366 subest.
 *
 * Los cuatro conservan su ID original (para mapear transformadores del viejo) y
 * su estado: deleted=1 entra como soft-delete (deleted_at) → queda en papelera.
 * NO se crean filas sintéticas: si una ubicación no tiene área, o un área no
 * tiene subestación, se respeta tal cual estaba en el viejo.
 *
 * Unicidad de customers (índices que incluyen soft-deleted, no parciales):
 * cod único por (tenant, país). Se deduplica dando prioridad al cliente activo;
 * los duplicados (basura del viejo) quedan sin cod.
 *
 * Todo en el workspace demo (tenant_id = 1). Idempotente: salta clientes ya
 * existentes y solo arma la jerarquía de los recién creados.
 */
class CustomersSeeder extends Seeder
{
    private const TENANT_ID = 1;

    public function run(): void
    {
        $custPath = database_path('seeders/data/customers.csv');
        if (!is_file($custPath)) {
            $this->command?->warn('customers.csv no encontrado; se omite.');
            return;
        }

        $isoToCountry = DB::table('countries')->whereNull('deleted_at')
            ->pluck('id', 'iso_code')
            ->mapWithKeys(fn ($id, $iso) => [mb_strtoupper($iso) => $id])->all();

        $custRows = $this->readCsv($custPath);
        $locRows  = $this->readCsv(database_path('seeders/data/customer_locations.csv'));
        $areaRows = $this->readCsv(database_path('seeders/data/customer_areas.csv'));
        $subRows  = $this->readCsv(database_path('seeders/data/customer_substations.csv'));

        // Normalizar clientes + resolver país.
        $customers = [];
        foreach ($custRows as $row) {
            [$id, $name, $cod, $iso, $address, $deleted] = array_pad($row, 6, null);
            $id = (int) $id;
            $name = trim((string) $name);
            $iso = mb_strtoupper(trim((string) $iso));
            $cod = trim((string) $cod);
            $cod = $cod === '' ? null : mb_substr($cod, 0, 50);
            $address = trim((string) $address);
            $address = $address === '' ? null : mb_substr($address, 0, 255);
            if ($id <= 0 || $name === '' || !isset($isoToCountry[$iso])) {
                continue;
            }
            $customers[$id] = [
                'id' => $id, 'name' => $name, 'cod' => $cod, 'address' => $address,
                'country_id' => $isoToCountry[$iso], 'deleted' => (string) $deleted === '1',
            ];
        }

        // Excepción pedida: Abengoa se restaura (el viejo lo tenía borrado, pero
        // tiene un trafo activo y se quiere vivo). Solo Abengoa, no otros.
        foreach ($customers as &$abengoa) {
            if (str_starts_with(mb_strtolower($abengoa['name']), 'abengoa')) {
                $abengoa['deleted'] = false;
            }
        }
        unset($abengoa);

        // cod único por (tenant, país) SOLO entre activos (el índice es parcial:
        // ignora soft-deleted). Los borrados conservan su cod real del viejo. Si
        // dos activos chocaran, el de id menor se queda con el cod y el resto null.
        $active = array_filter($customers, fn ($c) => !$c['deleted']);
        usort($active, fn ($a, $b) => $a['id'] <=> $b['id']);
        $codClaimed = [];
        foreach ($active as $c) {
            if ($c['cod'] === null) {
                continue;
            }
            $key = $c['country_id'] . '|' . mb_strtolower($c['cod']);
            if (isset($codClaimed[$key])) {
                $customers[$c['id']]['cod'] = null;
            } else {
                $codClaimed[$key] = true;
            }
        }

        $now = now();
        $stats = ['cust' => 0, 'cust_trash' => 0, 'loc' => 0, 'area' => 0, 'sub' => 0];

        DB::transaction(function () use (
            $customers, $locRows, $areaRows, $subRows, $now, &$stats
        ) {
            // Solo metadatos de soft-delete (compartido por customers + jerarquía).
            // is_active es exclusivo de customers; la jerarquía ya no lo tiene.
            $delMeta = fn (bool $d) => [
                'deleted_by'          => $d ? 1 : null,
                'deleted_description' => $d ? 'Migrado como borrado del sistema viejo.' : null,
                'deleted_at'          => $d ? $now : null,
            ];
            // Nombre fiel; solo coerciona vacío → '-' (la columna es NOT NULL).
            $nm = fn (string $s) => ($s === '' ? '-' : mb_substr($s, 0, 150));

            // ── Clientes (ID explícito) ──
            $createdCust = [];
            foreach ($customers as $c) {
                if (DB::table('customers')->where('id', $c['id'])->exists()) {
                    continue; // idempotente
                }
                DB::table('customers')->insert(array_merge([
                    'id' => $c['id'], 'slug' => Str::random(22), 'tenant_id' => self::TENANT_ID,
                    'country_id' => $c['country_id'], 'cod' => $c['cod'], 'name' => $c['name'],
                    'address' => $c['address'], 'is_active' => !$c['deleted'],
                    'created_by' => 1, 'created_at' => $now, 'updated_at' => $now,
                ], $delMeta($c['deleted'])));
                $createdCust[$c['id']] = true;
                $c['deleted'] ? $stats['cust_trash']++ : $stats['cust']++;
            }

            // ── Ubicaciones (ID explícito) de clientes recién creados ──
            $insertedLocs = [];
            foreach ($locRows as $r) {
                [$id, $custId, $name, $del] = array_pad($r, 4, null);
                $custId = (int) $custId;
                if (empty($createdCust[$custId])) {
                    continue;
                }
                DB::table('customer_locations')->insert(array_merge([
                    'id' => (int) $id, 'slug' => Str::random(22), 'customer_id' => $custId,
                    'name' => $nm(trim((string) $name)),
                    'tenant_id' => self::TENANT_ID, 'created_by' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ], $delMeta((string) $del === '1')));
                $insertedLocs[(int) $id] = true;
                $stats['loc']++;
            }

            // ── Áreas (ID explícito) de ubicaciones insertadas ──
            $insertedAreas = [];
            foreach ($areaRows as $r) {
                [$id, $locId, $name, $del] = array_pad($r, 4, null);
                $locId = (int) $locId;
                if (empty($insertedLocs[$locId])) {
                    continue;
                }
                DB::table('customer_areas')->insert(array_merge([
                    'id' => (int) $id, 'slug' => Str::random(22), 'customer_location_id' => $locId,
                    'name' => $nm(trim((string) $name)),
                    'tenant_id' => self::TENANT_ID, 'created_by' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ], $delMeta((string) $del === '1')));
                $insertedAreas[(int) $id] = true;
                $stats['area']++;
            }

            // ── Subestaciones (ID explícito) de áreas insertadas ──
            foreach ($subRows as $r) {
                [$id, $areaId, $name, $del] = array_pad($r, 4, null);
                $areaId = (int) $areaId;
                if (empty($insertedAreas[$areaId])) {
                    continue;
                }
                DB::table('customer_substations')->insert(array_merge([
                    'id' => (int) $id, 'slug' => Str::random(22), 'customer_area_id' => $areaId,
                    'name' => $nm(trim((string) $name)),
                    'tenant_id' => self::TENANT_ID, 'created_by' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ], $delMeta((string) $del === '1')));
                $stats['sub']++;
            }
        });

        // Resetear secuencias (pgsql): insertamos IDs explícitos.
        if (DB::getDriverName() === 'pgsql') {
            foreach (['customers', 'customer_locations', 'customer_areas', 'customer_substations'] as $tbl) {
                DB::statement("SELECT setval('{$tbl}_id_seq', COALESCE((SELECT MAX(id) FROM {$tbl}), 0) + 1, false)");
            }
        }

        $this->command?->info(
            "Customers seeded: {$stats['cust']} activos + {$stats['cust_trash']} en papelera · " .
            "{$stats['loc']} ubicaciones · {$stats['area']} áreas · {$stats['sub']} subestaciones."
        );
    }

    /** Lee un CSV (con header) a array de filas; vacío si no existe. */
    private function readCsv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        array_shift($rows); // header
        return $rows;
    }
}
