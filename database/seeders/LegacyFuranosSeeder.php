<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LegacyFuranosSeeder — importa las muestras REALES de furanos (compuestos
 * furánicos en ppb) del sistema viejo (Ruby) al esquema nuevo.
 *
 *   database/seeders/data/furanos_legacy.sql
 *
 * Mapeo viejo (furanos) → nuevo (furanos): los compuestos son directos.
 *   transformer_id (igual) · date_rehearsal→sample_date · deleted→soft-delete
 *   num_fal→fal · num_hme→hme · num_ace→ace · num_mfu→mfu · num_fua→fua
 *
 * El diagnóstico (dp/rating/condition) NO se trae: el motor nuevo lo recalcula
 * al leer (Chendong: fal→DP). Solo inserta los compuestos crudos + fecha.
 *
 * Idempotente: salta los ids ya existentes y las muestras de trafos que no
 * existen. Si falta el archivo, se omite.
 */
class LegacyFuranosSeeder extends Seeder
{
    private const TENANT = 1;

    /** Ruta del dump (inyectable en tests). */
    public ?string $file = null;

    /** Columnas del dump, en orden. */
    private const COLS = [
        'id', 'transformer_id', 'date_rehearsal', 'num_fal', 'num_hme', 'num_ace',
        'num_mfu', 'num_fua', 'deleted', 'created_at', 'updated_at',
    ];

    /** Compuesto viejo → columna nueva. */
    private const FURAN = [
        'num_fal' => 'fal', 'num_hme' => 'hme', 'num_ace' => 'ace',
        'num_mfu' => 'mfu', 'num_fua' => 'fua',
    ];

    public function run(): void
    {
        $path = $this->file ?? database_path('seeders/data/furanos_legacy.sql');
        if (!is_file($path)) {
            $this->command?->warn('LegacyFuranosSeeder: no hay muestras de furanos que importar.');
            $this->command?->warn('  Coloca el dump en: database/seeders/data/furanos_legacy.sql');
            return;
        }

        $trafos   = DB::table('transformers')->pluck('id')->flip();   // set de trafo ids válidos
        $existing = DB::table('furanos')->pluck('id')->flip();

        $rows = $this->parse(file_get_contents($path));
        $batch = []; $imported = 0; $skipped = 0;

        foreach ($rows as $r) {
            $v = array_combine(self::COLS, $r);

            if (!ctype_digit(trim((string) $v['id']))) {
                continue; // cabecera de columnas / ruido
            }

            $id  = (int) $v['id'];
            $trf = (int) $v['transformer_id'];

            // Sin trafo válido o ya existente → se omite.
            if (!isset($trafos[$trf]) || isset($existing[$id])) {
                $skipped++;
                continue;
            }

            $entry = [
                'id'             => $id,
                'transformer_id' => $trf,
                'sample_date'    => $this->tsMuestra($v['date_rehearsal']),
                'tenant_id'      => self::TENANT,
                'created_by'     => 1,
                'deleted_at'     => $this->str($v['deleted']) === '1' ? Carbon::parse($v['updated_at']) : null,
                'created_at'     => $this->ts($v['created_at']),
                'updated_at'     => $this->ts($v['updated_at']),
            ];
            foreach (self::FURAN as $old => $new) {
                $entry[$new] = $this->num($v[$old]);
            }
            $batch[] = $entry;
            $imported++;

            if (count($batch) >= 500) {
                DB::table('furanos')->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('furanos')->insert($batch);
        }

        $this->command?->info("LegacyFuranosSeeder: {$imported} muestras importadas, {$skipped} omitidas (trafo faltante o ya existente).");
    }

    // ── Helpers de valor ─────────────────────────────────────────────────────
    private function str($v): string { return $v === null ? '' : (string) $v; }
    private function num($v): ?float { $s = $this->str($v); return $s === '' ? null : (float) $s; }

    /**
     * Fecha de MUESTRA (no de auditoría), ya normalizada.
     *
     * Dos arreglos que deben vivir acá y no en una migración: `setup:project`
     * hace `migrate:fresh --seed`, así que las migraciones de datos corren sobre
     * una base vacía y después el seeder volvería a meter el problema.
     *
     *  a) Las 05:00 son medianoche de Perú convertida a UTC por la migración del
     *     sistema viejo, no una hora de laboratorio. Se llevan a 00:00.
     *  b) Años imposibles por tipeo en el origen: 0006→2006, 0218→2018,
     *     0202→2020. Solo se corrigen los inequívocos, contrastados contra las
     *     demás muestras del mismo transformador (el 0202 se confirmó porque la
     *     cromatografía del mismo trafo tiene una muestra ese mismo día: el
     *     laboratorio corrió las dos pruebas sobre la misma toma).
     */
    private function tsMuestra($v): string
    {
        $f = Carbon::parse($this->str($v) ?: now());

        // Años rotos por tipeo en el origen. NO se usa una fórmula genérica: el
        // año correcto no se deduce del roto (0006→2006 sería "sumar 2000", pero
        // 0218→2018 es insertar un cero, no anteponer un 2). Se corrigen SOLO los
        // casos verificados uno a uno contra las demás muestras del mismo trafo.
        if ($f->year < 1000) {
            $f = match ($f->format('Y-m-d')) {
                '0006-04-22' => $f->copy()->setDate(2006, 4, 22),
                '0218-02-06' => $f->copy()->setDate(2018, 2, 6),
                '0202-02-28' => $f->copy()->setDate(2020, 2, 28),
                default      => $f,   // desconocido: se deja como está y se ve
            };
        }

        if ($f->format('H:i') === '05:00') {
            $f->startOfDay();
        }

        return $f->toDateTimeString();
    }

    private function ts($v): string
    {
        $s = $this->str($v);
        return $s === '' ? now()->toDateTimeString() : Carbon::parse($s)->toDateTimeString();
    }

    /**
     * Parser de las tuplas VALUES (...) del dump MySQL (respeta '...' con escapes).
     * Solo conserva tuplas con el nº de columnas esperado.
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
            $i++;

            $fields = [];
            $buf = '';
            $wasString = false;
            $closed = false;

            while ($i < $len) {
                $c = $sql[$i];

                if ($c === "'") {
                    $wasString = true;
                    $buf = '';
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

                $buf .= $c; $i++;
            }

            if ($closed && count($fields) === count(self::COLS)) {
                $rows[] = $fields;
            }
        }
        return $rows;
    }

    private function fieldValue(string $buf, bool $wasString): ?string
    {
        if ($wasString) {
            return $buf;
        }
        $t = trim($buf);
        return strcasecmp($t, 'NULL') === 0 ? null : $t;
    }
}
