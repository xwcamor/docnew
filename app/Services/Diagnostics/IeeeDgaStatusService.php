<?php

namespace App\Services\Diagnostics;

use App\Support\Diagnostics\RuleData;

/**
 * IeeeDgaStatusService — algoritmo DGA Status 1/2/3 de IEEE C57.104-2019.
 *
 * Combina 4 condiciones sobre las muestras de cromatografía:
 *   - Tabla 1: todas las concentraciones de la ÚLTIMA muestra < límite (por O2/N2 y edad).
 *   - Tabla 3: todos los deltas (última − anterior) < límite (por O2/N2). Acetileno: sin aumento.
 *   - Tabla 4: todas las TASAS de generación < límite (IeeeDgaRateService).
 *   - Tabla 2: ningún gas de la última muestra supera el límite (95th, por O2/N2 y edad).
 *
 * Flujo (diagrama del PDF):
 *   Status 1 (normal): Tabla1 ✓ Y Tabla3 ✓ Y Tabla4 ✓.
 *   Status 3 (falla):  algún gas > Tabla2  O  alguna tasa > Tabla4.
 *   Status 2 (vigilar): el resto.
 *
 * Las condiciones no evaluables (sin muestra anterior para deltas, o período de
 * Tabla 4 fuera de 4-24 meses) no bloquean Status 1 ni fuerzan falla (= "Omitir
 * Tabla 4" del sistema viejo); se reportan como null para mostrarlo en la UI.
 *
 * Datos en ieee_c57104_tables123.json + ieee_c57104_table4.json (editables).
 */
class IeeeDgaStatusService
{
    private array $cfg;
    private IeeeDgaRateService $rate;

    public function __construct(?array $cfg = null, ?IeeeDgaRateService $rate = null)
    {
        $this->cfg = $cfg ?? RuleData::get('ieee_c57104_tables123');
        $this->rate = $rate ?? new IeeeDgaRateService();
    }

    /**
     * @param  iterable   $samples        Chromatographical (cualquier orden).
     * @param  int|null    $ageYears       edad del transformador en años (null = desconocida).
     * @param  array|null  $rateSampleIds  selección MANUAL de muestras para Tabla 4
     *                                      (IDs). null = automático.
     */
    public function evaluate(iterable $samples, ?int $ageYears = null, ?array $rateSampleIds = null): ?array
    {
        $all = collect($samples)
            ->filter(fn ($s) => $s->sample_date !== null)
            ->sortBy(fn ($s) => $this->ts($s->sample_date))
            ->values();
        if ($all->isEmpty()) {
            return null;
        }

        $thr = (float) ($this->cfg['o2n2_threshold'] ?? 0.2);
        $gases = $this->cfg['gases'] ?? ['h2', 'ch4', 'c2h6', 'c2h4', 'c2h2', 'co', 'co2'];

        $last = $all->last();
        $o2 = (float) ($last->o2 ?? 0);
        $n2 = (float) ($last->n2 ?? 0);
        $ratio = $n2 > 0 ? $o2 / $n2 : null;
        $col = ($ratio !== null && $ratio > $thr) ? 'gt02' : 'le02';
        $ageBucket = $this->ageBucket($ageYears);

        // Tabla 1 (90th): concentraciones de la última muestra.
        $t1Ok = true;
        $t1 = [];
        foreach ($gases as $g) {
            $v = $last->{$g};
            if ($v === null) {
                continue;
            }
            $lim = $this->cfg['table1'][$col][$g][$ageBucket] ?? null;
            $ex = $lim !== null && (float) $v > $lim;
            $t1[$g] = ['value' => (float) $v, 'limit' => $lim, 'exceeded' => $ex];
            if ($ex) {
                $t1Ok = false;
            }
        }

        // Tabla 2 (95th): ¿algún gas supera?
        $t2Exceeded = false;
        $t2 = [];
        foreach ($gases as $g) {
            $v = $last->{$g};
            if ($v === null) {
                continue;
            }
            $lim = $this->cfg['table2'][$col][$g][$ageBucket] ?? null;
            $ex = $lim !== null && (float) $v > $lim;
            $t2[$g] = ['value' => (float) $v, 'limit' => $lim, 'exceeded' => $ex];
            if ($ex) {
                $t2Exceeded = true;
            }
        }

        // Tabla 3 (deltas última − anterior).
        $t3Ok = null;
        $t3 = [];
        $prev = $all->count() >= 2 ? $all[$all->count() - 2] : null;
        if ($prev) {
            $t3Ok = true;
            $anyInc = !empty($this->cfg['table3']['c2h2_any_increase']);
            foreach ($gases as $g) {
                $cur = $last->{$g};
                $pr = $prev->{$g};
                if ($cur === null || $pr === null) {
                    continue;
                }
                $delta = (float) $cur - (float) $pr;
                if ($g === 'c2h2' && $anyInc) {
                    $lim = 0.0;
                    $ex = $delta > 0;
                } else {
                    $lim = $this->cfg['table3'][$col][$g] ?? null;
                    $ex = $lim !== null && $delta > $lim;
                }
                $t3[$g] = ['delta' => round($delta, 2), 'limit' => $lim, 'exceeded' => $ex];
                if ($ex) {
                    $t3Ok = false;
                }
            }
        }

        // Tabla 4 (tasas). Automático (motor elige ventana) o MANUAL (el
        // diagnosticador eligió las muestras → se usan exactamente esas).
        $manual = is_array($rateSampleIds) && count($rateSampleIds) > 0;
        if ($manual) {
            $ids = array_map('intval', $rateSampleIds);
            $rateSamples = $all->filter(fn ($s) => in_array((int) $s->id, $ids, true))->values();
            $rate = $this->rate->evaluate($rateSamples, false);
        } else {
            $rate = $this->rate->evaluate($all, true);
        }
        $t4Ok = $rate['applicable'] ? !$rate['any_exceeded'] : null;

        // Flujo Status 1/2/3.
        $cond1 = ($t1Ok === true) && ($t3Ok !== false) && ($t4Ok !== false);
        if ($cond1) {
            $status = 1;
        } elseif ($t2Exceeded || ($t4Ok === false)) {
            $status = 3;
        } else {
            $status = 2;
        }

        return [
            'status'            => $status,
            'mode'              => $manual ? 'manual' : 'auto',
            'rate_sample_ids'   => $rate['sample_ids'] ?? [],
            'o2n2'              => $ratio === null ? null : round($ratio, 3),
            'column'            => $col,
            'age_bucket'        => $ageBucket,
            'table1_ok'         => $t1Ok,
            'table2_exceeded'   => $t2Exceeded,
            'table3_ok'         => $t3Ok,
            'table4_ok'         => $t4Ok,
            'table4_applicable' => $rate['applicable'],
            'rate'              => $rate,
            'detail'            => ['table1' => $t1, 'table2' => $t2, 'table3' => $t3],
        ];
    }

    private function ageBucket(?int $age): string
    {
        if ($age === null) {
            return 'unknown';
        }
        if ($age <= 9) {
            return 'le9';
        }
        if ($age <= 30) {
            return '9_30';
        }
        return 'gt30';
    }

    private function ts($date): int
    {
        return $date instanceof \DateTimeInterface ? $date->getTimestamp() : (int) strtotime((string) $date);
    }
}
