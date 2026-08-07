<?php

namespace App\Console\Commands;

use App\Models\Transformer;
use App\Services\Diagnostics\HealthIndexService;
use Database\Seeders\LegacyTransformersSeeder;
use Illuminate\Console\Command;

/**
 * Verificación masiva contra el sistema viejo (Ruby):
 *
 *   php artisan verify:legacy [--tolerance=5] [--detail=15]
 *
 * Lee el snapshot de Índice de Salud que el sistema viejo dejó cacheado en su
 * dump (transformers_legacy.sql: num_health + state_health) y lo compara con
 * lo que calcula el motor NUEVO sobre las muestras importadas. No depende de
 * columnas de la BD (que el motor nuevo pudo recalcular): el valor viejo sale
 * SIEMPRE del dump.
 *
 * Esto es la prueba de paridad de la migración: si el motor nuevo reproduce el
 * HI del viejo dentro de la tolerancia, las 234+ reglas portadas son fieles.
 * Las diferencias esperables vienen de: redondeos, muestras deduplicadas, y
 * pruebas que el viejo incluía con datos que el nuevo descarta (o viceversa).
 */
class VerifyLegacyHealthCommand extends Command
{
    protected $signature = 'verify:legacy
        {--tolerance=5 : Diferencia de HI (puntos) aceptada como coincidencia}
        {--detail=15 : Cuántas discrepancias listar, ordenadas por delta}
        {--file= : Ruta alternativa del dump de transformadores}';

    protected $description = 'Compara el Índice de Salud del sistema viejo (dump) contra el motor nuevo, trafo por trafo';

    public function handle(HealthIndexService $service): int
    {
        $path = $this->option('file') ?: database_path('seeders/data/transformers_legacy.sql');
        if (!is_file($path)) {
            $this->error("No existe el dump: {$path}");
            return self::FAILURE;
        }

        // HI viejo por id, directo del dump (mismo parser del seeder legacy).
        // OJO: el viejo guardaba '0'/'Muy Malo' como DEFAULT de registro sin
        // diagnosticar (sus conclusiones con_cro/con_fiq quedan NULL). Esos no
        // son diagnósticos reales → se excluyen y se cuentan aparte.
        $seeder = new LegacyTransformersSeeder();
        $legacy = [];
        $neverDiagnosed = 0;
        foreach ($seeder->parse(file_get_contents($path)) as $r) {
            if (count($r) < 22 || !ctype_digit(trim((string) $r[0]))) {
                continue;
            }
            $hi    = trim((string) ($r[15] ?? ''));   // num_health
            $state = trim((string) ($r[16] ?? ''));   // state_health
            $del   = trim((string) ($r[27] ?? '0'));  // deleted
            if ($hi === '' || !is_numeric($hi) || $del === '1') {
                continue;
            }
            $hasConclusions = trim((string) ($r[18] ?? '')) !== '' || trim((string) ($r[20] ?? '')) !== ''; // con_cro / con_fiq
            if (!$hasConclusions && (float) $hi == 0.0) {
                $neverDiagnosed++;
                continue;
            }
            $legacy[(int) $r[0]] = ['hi' => (float) $hi, 'state' => $state, 'snap' => trim((string) ($r[29] ?? ''))];
        }
        $this->info('Snapshot viejo: ' . count($legacy) . ' transformadores con diagnóstico real en el dump'
            . ($neverDiagnosed ? " ({$neverDiagnosed} excluidos: el viejo nunca los diagnosticó — HI 0 default sin conclusiones)." : '.'));

        $tolerance = (float) $this->option('tolerance');
        $compared = 0; $stateMatch = 0; $withinTol = 0; $newNoData = 0;
        // Trafos con pruebas FALTANTES: el viejo las castigaba en el promedio,
        // el nuevo usa peso dinámico (decisión de diseño deliberada). Ahí la
        // diferencia es esperada y NO mide fidelidad de reglas → aparte.
        $partial = 0; $partialWithin = 0;
        $mismatches = [];

        Transformer::with('oilType', 'transformerType')
            ->whereIn('id', array_keys($legacy))
            ->chunkById(100, function ($chunk) use (&$compared, &$stateMatch, &$withinTol, &$newNoData, &$partial, &$partialWithin, &$mismatches, $legacy, $service, $tolerance) {
                foreach ($chunk as $t) {
                    $old = $legacy[$t->id];
                    $new = $service->evaluate($t);

                    if (!$new->hasData()) {
                        // El viejo tenía HI pero el nuevo no diagnostica (sin
                        // muestras importadas o aceite sin reglas).
                        $newNoData++;
                        continue;
                    }

                    $delta = abs($new->index - $old['hi']);
                    $sameState = $old['state'] !== '' && $new->condition === $old['state'];

                    if ($new->isPartial()) {
                        // Diferencia esperada por peso dinámico: no mide reglas.
                        $partial++;
                        if ($delta <= $tolerance) $partialWithin++;
                        continue;
                    }

                    $compared++;
                    if ($sameState) $stateMatch++;
                    if ($delta <= $tolerance) $withinTol++;

                    if ($delta > $tolerance || (!$sameState && $old['state'] !== '')) {
                        // ¿El snapshot viejo quedó desactualizado en SU sistema?
                        // (muestras posteriores a la fecha del export → el HI
                        // viejo no las vio; el nuevo sí. No es error de reglas.)
                        $lastSample = collect([
                            $t->latestChromatographical()?->sample_date,
                            $t->latestFiqui()?->sample_date,
                            $t->latestFurano()?->sample_date,
                        ])->filter()->max();
                        $stale = $old['snap'] !== '' && $lastSample && $lastSample->gt(\Illuminate\Support\Carbon::parse($old['snap']));

                        $mismatches[] = [
                            'id'     => $t->id,
                            'serial' => $t->serial ?: $t->tag,
                            'old_hi' => $old['hi'],
                            'new_hi' => round($new->index, 1),
                            'delta'  => round($delta, 1),
                            'old_st' => $old['state'] ?: '—',
                            'new_st' => $new->condition ?? '—',
                            'stale'  => $stale,
                        ];
                    }
                }
            });

        $this->newLine();
        $this->info('── Paridad de reglas (trafos con TODAS las pruebas: ambos motores promedian lo mismo) ──');
        $this->line(sprintf('  Comparados:            %d', $compared));
        $this->line(sprintf('  HI dentro de ±%.0f pts:  %d (%.1f%%)', $tolerance, $withinTol, $compared ? $withinTol / $compared * 100 : 0));
        $this->line(sprintf('  Mismo estado/semáforo: %d (%.1f%%)', $stateMatch, $compared ? $stateMatch / $compared * 100 : 0));
        $this->line(sprintf('  Discrepancias:         %d', count($mismatches)));
        $this->newLine();
        $this->info('── Fuera de la paridad (esperado) ──');
        $this->line(sprintf('  Diagnóstico parcial (peso dinámico nuevo vs castigo viejo — decisión de diseño): %d (de ellos, %d igual coinciden ±%.0f)', $partial, $partialWithin, $tolerance));
        $this->line(sprintf('  Viejo con HI, nuevo sin datos: %d', $newNoData));

        if ($mismatches) {
            $staleCount = count(array_filter($mismatches, fn ($m) => $m['stale']));
            $this->line(sprintf('  De las discrepancias, %d tienen muestras POSTERIORES al snapshot viejo (su HI quedó desactualizado en el sistema viejo — no es error de reglas).', $staleCount));

            // Las que importan: sin muestras nuevas → mismas entradas, distinto resultado.
            $real = array_values(array_filter($mismatches, fn ($m) => !$m['stale']));
            usort($real, fn ($a, $b) => $b['delta'] <=> $a['delta']);
            $top = array_slice($real, 0, max(1, (int) $this->option('detail')));
            $this->newLine();
            $this->info('── Discrepancias REALES a investigar (sin muestras posteriores al snapshot): ' . count($real) . ' ──');
            if ($top) {
                $this->table(
                    ['ID', 'Serie', 'HI viejo', 'HI nuevo', 'Δ', 'Estado viejo', 'Estado nuevo'],
                    array_map(fn ($m) => [$m['id'], $m['serial'], $m['old_hi'], $m['new_hi'], $m['delta'], $m['old_st'], $m['new_st']], $top)
                );
                $this->line('  (investigar con: php artisan diagnose:health {id} y diagnose:cromas {id})');
            }
        }

        return self::SUCCESS;
    }
}
