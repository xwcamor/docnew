<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\CustomerSubstation;
use App\Models\OilType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LegacyTransformersSeeder — importa la cabecera de los transformadores REALES del
 * sistema viejo (Ruby) al esquema nuevo. Lee el dump tal cual se exportó:
 *
 *   database/seeders/data/transformers_legacy.sql
 *   (los INSERT INTO `transformers` (...) VALUES (...),(...); originales)
 *
 * Mapeo viejo → nuevo (la mayoría es directo porque connection_types, preservations,
 * brands y customer_substations conservan sus IDs originales):
 *   num_serie→serial · num_tag→tag · customer_substation_id (igual) · customer_id
 *   (derivado de subestación→área→ubicación) · transformer_type_id (1-3) ·
 *   connection_type_id (1-16) · transformer_preservation_id (1-4) · mark_id→brand_id ·
 *   oil_type_id (por código) · num_vol→voltage_kv · num_pot→power_mva · age→año ·
 *   num_fas→phases · num_health→health_index · state_health→health_state · deleted→soft-delete.
 *
 * NO trae las muestras de ensayos (cromas/fiquis/furanos): el dump es solo el header.
 * El health_index/state son el snapshot cacheado del sistema viejo.
 *
 * Idempotente: solo inserta los IDs que aún no existen. Si falta el archivo, se omite.
 */
class LegacyTransformersSeeder extends Seeder
{
    private const TENANT = 1;

    /** Ruta del dump (inyectable en tests). */
    public ?string $file = null;

    /**
     * Aceite del sistema viejo (oil_types) → código del nuevo. Mapeo decidido con
     * el catálogo viejo a la vista:
     *   1 Mineral→mineral · 4 Silicona→silicona · 6 Vegetal Girasol→girasol.
     *   2,7 Éster Sintético→ester_sintetico.
     *   3 Éster Natural, 5 Éster Vegetal, 9 Vegetal→vegetal_soya (éster natural
     *     genérico; 5 es el port directo del "Éster Vegetal" viejo).
     *   8 "-"→ninguno (equipo sin aceite, no diagnostica).
     */
    private const OIL = [
        1 => 'mineral',
        2 => 'ester_sintetico',
        3 => 'vegetal_soya',
        4 => 'silicona',
        5 => 'vegetal_soya',
        6 => 'vegetal_girasol',
        7 => 'ester_sintetico',
        8 => 'ninguno',
        9 => 'vegetal_soya',
    ];

    /**
     * Conmutador del sistema viejo (conmutation_types) → código del nuevo
     * (tap_changer_types). Mismo orden en ambos catálogos:
     *   1=OLTC, 2=DETC, 3="-". Se mapea por código (no por id) para no depender
     *   del orden del seeder; el resultado es 1:1 igual.
     */
    private const CONMUT = [1 => 'oltc', 2 => 'detc', 3 => 'none'];

    /** Columnas del dump, en orden. */
    private const COLS = [
        'id', 'customer_substation_id', 'transformer_type_id', 'connection_type_id',
        'conmutation_type_id', 'transformer_preservation_id', 'oil_type_id', 'mark_id',
        'num_serie', 'num_tag', 'num_vol', 'num_pot', 'age', 'num_fas', 'num_tap',
        'num_health', 'state_health', 'color_health', 'con_cro', 'rec_cro', 'con_fiq',
        'rec_fiq', 'duval_triangle_first_image', 'duval_pentagon_first_image',
        'has_special_testing_cro', 'has_special_testing_fiq', 'has_special_testing_fur',
        'deleted', 'created_at', 'updated_at',
    ];

    public function run(): void
    {
        $path = $this->file ?? database_path('seeders/data/transformers_legacy.sql');
        if (!is_file($path)) {
            $this->command?->warn('LegacyTransformersSeeder: no hay transformadores que importar.');
            $this->command?->warn('  Coloca el dump real en: database/seeders/data/transformers_legacy.sql');
            $this->command?->warn('  (los INSERT INTO `transformers` ... VALUES del sistema viejo) y vuelve a correr setup:project.');
            return;
        }

        // Mapas de resolución (una vez).
        $oilIdByCode = OilType::pluck('id', 'code');            // code → new id
        $brandIds    = Brand::withTrashed()->pluck('id')->flip(); // incl. soft-deleted: el viejo referenciaba marcas borradas
        $existing    = DB::table('transformers')->pluck('id')->flip();
        // Conmutador: el orden viejo≠nuevo → se mapea por código (ver CONMUT).
        $tapIdByCode = DB::table('tap_changer_types')->pluck('id', 'code');

        // Cadena subestación → customer_id (una sola query con joins).
        $custBySub = DB::table('customer_substations as s')
            ->join('customer_areas as a', 'a.id', '=', 's.customer_area_id')
            ->join('customer_locations as l', 'l.id', '=', 'a.customer_location_id')
            ->pluck('l.customer_id', 's.id');                   // sub id → customer id

        $rows = $this->parse(file_get_contents($path));
        $batch = []; $imported = 0; $skipped = 0;

        foreach ($rows as $r) {
            $v = array_combine(self::COLS, $r);

            // Descartar la cabecera de columnas (id no numérico) y ruido.
            if (!ctype_digit(trim((string) $v['id']))) {
                continue;
            }

            $id  = (int) $v['id'];
            $sub = (int) $v['customer_substation_id'];
            $customerId = $custBySub[$sub] ?? null;

            // Sin subestación/cliente válido no se puede ubicar el trafo → se omite.
            if ($customerId === null || isset($existing[$id])) {
                $skipped++;
                continue;
            }

            $oilCode = self::OIL[(int) $v['oil_type_id']] ?? 'mineral';
            $brand   = (int) $v['mark_id'];
            $age     = (int) $v['age'];
            $conn    = (int) $v['connection_type_id'];
            $presv   = (int) $v['transformer_preservation_id'];
            $tapCode = self::CONMUT[(int) $v['conmutation_type_id']] ?? null;   // oltc/detc/none

            $batch[] = [
                'id'                          => $id,
                'slug'                        => Str::random(22),
                'serial'                      => ($s = $this->str($v['num_serie'])) === '' ? ('SN-' . $id) : $s,
                'tag'                         => ($s = $this->str($v['num_tag'])) === '' ? '-' : $s,
                'customer_id'                 => $customerId,
                'customer_substation_id'      => $sub,
                'transformer_type_id'         => in_array((int) $v['transformer_type_id'], [1, 2, 3], true) ? (int) $v['transformer_type_id'] : 1,
                'connection_type_id'          => ($conn >= 1 && $conn <= 16) ? $conn : null,
                'tap_changer_type_id'         => $tapCode ? ($tapIdByCode[$tapCode] ?? null) : null,
                'transformer_preservation_id' => ($presv >= 1 && $presv <= 4) ? $presv : null,
                'oil_type_id'                 => $oilIdByCode[$oilCode] ?? $oilIdByCode['mineral'] ?? 1,
                'brand_id'                    => isset($brandIds[$brand]) ? $brand : null,
                'voltage_kv'                  => $this->num($v['num_vol']),
                'power_mva'                   => $this->num($v['num_pot']),
                'manufacture_year'            => $age > 1900 ? $age : null,
                'phases'                      => ['1' => 'single', '2' => 'two', '3' => 'three'][$this->str($v['num_fas'])] ?? null,
                // health_index del snapshot viejo solo como placeholder inicial;
                // RecalculateTransformerHealthSeeder lo recalcula con el motor
                // nuevo y puebla health_rating (0-4). El texto state_health viejo
                // ya no se guarda (la columna health_state se reemplazó por rating).
                'health_index'                => $this->str($v['num_health']) === '' ? null : (int) $v['num_health'],
                'tenant_id'                   => self::TENANT,
                'created_by'                  => 1,
                'deleted_at'                  => $this->str($v['deleted']) === '1' ? Carbon::parse($v['updated_at']) : null,
                'created_at'                  => $this->ts($v['created_at']),
                'updated_at'                  => $this->ts($v['updated_at']),
            ];
            $imported++;

            if (count($batch) >= 500) {
                DB::table('transformers')->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('transformers')->insert($batch);
        }

        $this->command?->info("LegacyTransformersSeeder: {$imported} transformadores importados, {$skipped} omitidos (subestación faltante o ya existente).");
    }

    // ── Helpers de valor ─────────────────────────────────────────────────────
    private function str($v): string { return $v === null ? '' : (string) $v; }
    private function num($v): ?float { $s = $this->str($v); return $s === '' ? null : (float) $s; }
    private function ts($v): string
    {
        $s = $this->str($v);
        return $s === '' ? now()->toDateTimeString() : Carbon::parse($s)->toDateTimeString();
    }

    /**
     * Parser de las tuplas VALUES (...) del dump MySQL. Respeta strings con comillas
     * simples y escapes (\' \" \\ \n). Solo conserva tuplas con el nº de columnas
     * esperado (descarta cabeceras INSERT y ruido). Devuelve filas de 30 valores
     * (string para token/contenido, null para NULL literal).
     *
     * @return array<int,array<int,?string>>
     */
    public function parse(string $sql): array
    {
        $rows = [];
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            if ($sql[$i] !== '(') { $i++; continue; }
            $i++;                        // saltar '('

            $fields = [];
            $buf = '';                   // contenido del campo en curso
            $wasString = false;          // ¿el campo en curso fue un '...' ?
            $closed = false;

            while ($i < $len) {
                $c = $sql[$i];

                if ($c === "'") {        // inicio de string → consumir hasta cierre
                    $wasString = true;
                    $buf = '';           // descartar espacios previos: el campo ES el string
                    $i++;
                    while ($i < $len) {
                        $d = $sql[$i];
                        if ($d === '\\' && $i + 1 < $len) {
                            $n = $sql[$i + 1];
                            $buf .= match ($n) { 'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", default => $n };
                            $i += 2;
                            continue;
                        }
                        if ($d === "'") { $i++; break; }
                        $buf .= $d; $i++;
                    }
                    continue;
                }

                if ($c === ',' || $c === ')') {
                    $fields[] = $this->fieldValue($buf, $wasString);
                    $buf = ''; $wasString = false;
                    $i++;
                    if ($c === ')') { $closed = true; break; }
                    continue;
                }

                $buf .= $c; $i++;        // token sin comillas (número/NULL/espacios)
            }

            if ($closed && count($fields) === count(self::COLS)) {
                $rows[] = $fields;
            }
        }
        return $rows;
    }

    /** Valor final de un campo: string → su contenido; token NULL → null; número → su texto. */
    private function fieldValue(string $buf, bool $wasString): ?string
    {
        if ($wasString) {
            return $buf;                 // contenido ya desescapado (puede ser '')
        }
        $t = trim($buf);
        return strcasecmp($t, 'NULL') === 0 ? null : $t;
    }
}
