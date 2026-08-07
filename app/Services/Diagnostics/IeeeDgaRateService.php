<?php

namespace App\Services\Diagnostics;

use App\Support\Diagnostics\RuleData;
use Illuminate\Support\Collection;

/**
 * IeeeDgaRateService — método de TASA de generación de gas (IEEE C57.104-2019, Tabla 4).
 *
 * Toma 3 a 6 muestras de cromatografía dentro de una ventana de 4 a 24 meses,
 * calcula la PENDIENTE (mínimos cuadrados) de cada gas vs. tiempo en ppm/día,
 * la lleva a ppm/año (×365), y la compara contra la Tabla 4 (límite por ratio
 * O2/N2 y período). Devuelve si alguna tasa supera su límite ('tasa fuera de
 * norma') con el detalle por gas. Acetileno: cualquier tasa creciente (>0) = alarma.
 *
 * Esto es la pata "All Rates < Table 4" del algoritmo DGA Status 1/2/3.
 * Datos en ieee_c57104_table4.json (editable). El código solo tiene fórmulas.
 */
class IeeeDgaRateService
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? RuleData::get('ieee_c57104_table4');
    }

    /**
     * @param  iterable  $samples     Chromatographical (cualquier orden).
     * @param  bool       $pickWindow  true = el motor elige solo las últimas 3-6
     *                                  en ventana de 4-24 meses (automático);
     *                                  false = usa EXACTAMENTE las muestras dadas
     *                                  (selección manual del diagnosticador).
     * @return array{applicable:bool, reason:?string, period_months:?float, o2n2:?float,
     *               column:?string, bucket:?string, samples:int, any_exceeded:bool,
     *               per_gas:array<string,array{rate:float,limit:?float,exceeded:bool}>}
     */
    public function evaluate(iterable $samples, bool $pickWindow = true): array
    {
        $out = [
            'applicable'   => false, 'reason' => null, 'period_months' => null,
            'o2n2' => null, 'column' => null, 'bucket' => null, 'samples' => 0,
            'any_exceeded' => false, 'per_gas' => [],
        ];

        $min = (int) ($this->cfg['samples']['min'] ?? 3);
        $max = (int) ($this->cfg['samples']['max'] ?? 6);
        $minM = (float) ($this->cfg['period_months']['min'] ?? 4);
        $maxM = (float) ($this->cfg['period_months']['max'] ?? 24);

        // Ordenar por fecha.
        $all = collect($samples)
            ->filter(fn ($s) => $s->sample_date !== null)
            ->sortBy(fn ($s) => $this->ts($s->sample_date))
            ->values();

        if ($pickWindow) {
            // Automático: las MÁS RECIENTES (hasta `max`), achicando la ventana
            // (descartando la más vieja) hasta que el período sea ≤ max meses.
            $set = $all->slice(max(0, $all->count() - $max))->values();
            while ($set->count() > $min && $this->spanMonths($set) > $maxM) {
                $set = $set->slice(1)->values();
            }
        } else {
            // Manual: usar exactamente las muestras dadas (máx `max` por las dudas).
            $set = $all->count() > $max ? $all->slice($all->count() - $max)->values() : $all;
        }

        $n = $set->count();
        $out['samples'] = $n;
        if ($n < $min) {
            $out['reason'] = 'pocas_muestras';
            return $out;
        }

        $out['sample_ids'] = $set->map(fn ($s) => $s->id)->filter()->values()->all();

        $period = $this->spanMonths($set);
        $out['period_months'] = round($period, 1);
        if ($period < $minM || $period > $maxM) {
            $out['reason'] = 'periodo_fuera_4_24';
            return $out;
        }

        // Ratio O2/N2 de la muestra más reciente → elige columna de límites.
        $last = $set->last();
        $o2 = (float) ($last->o2 ?? 0);
        $n2 = (float) ($last->n2 ?? 0);
        $ratio = $n2 > 0 ? $o2 / $n2 : null;
        $out['o2n2'] = $ratio === null ? null : round($ratio, 3);

        $thr = (float) ($this->cfg['o2n2_threshold'] ?? 0.2);
        $col = ($ratio !== null && $ratio > $thr) ? 'gt02' : 'le02';
        $bucket = $period <= 9 ? 'p4_9' : 'p10_24';
        $out['column'] = $col;
        $out['bucket'] = $bucket;

        // x = días desde la muestra más vieja del set.
        $t0 = $this->ts($set->first()->sample_date);
        $xs = $set->map(fn ($s) => ($this->ts($s->sample_date) - $t0) / 86400.0)->all();

        $limits = $this->cfg['limits'][$col][$bucket] ?? [];
        $gases = $this->cfg['gases'] ?? ['h2', 'ch4', 'c2h6', 'c2h4', 'co', 'co2'];

        foreach ($gases as $g) {
            $ys = $set->map(fn ($s) => (float) ($s->{$g} ?? 0))->all();
            $slope = $this->slopePerDay($xs, $ys);
            $rateYear = $slope * 365.0;
            $limit = isset($limits[$g]) ? (float) $limits[$g] : null;
            $exceeded = $limit !== null && $rateYear > $limit;
            $out['per_gas'][$g] = ['rate' => round($rateYear, 2), 'rate_day' => round($slope, 4), 'limit' => $limit, 'exceeded' => $exceeded];
            if ($exceeded) {
                $out['any_exceeded'] = true;
            }
        }

        // Acetileno: cualquier tasa creciente (>0) es alarma.
        if (!empty($this->cfg['c2h2_any_increase'])) {
            $ys = $set->map(fn ($s) => (float) ($s->c2h2 ?? 0))->all();
            $slope = $this->slopePerDay($xs, $ys);
            $rateYear = $slope * 365.0;
            $exceeded = $rateYear > 0;
            $out['per_gas']['c2h2'] = ['rate' => round($rateYear, 2), 'rate_day' => round($slope, 4), 'limit' => 0.0, 'exceeded' => $exceeded];
            if ($exceeded) {
                $out['any_exceeded'] = true;
            }
        }

        $out['applicable'] = true;
        return $out;
    }

    /** Pendiente de mínimos cuadrados (ppm/día). */
    private function slopePerDay(array $xs, array $ys): float
    {
        $n = count($xs);
        $sx = array_sum($xs);
        $sy = array_sum($ys);
        $sxy = 0.0;
        $sxx = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sxy += $xs[$i] * $ys[$i];
            $sxx += $xs[$i] * $xs[$i];
        }
        $den = $n * $sxx - $sx * $sx;
        return $den == 0.0 ? 0.0 : ($n * $sxy - $sx * $sy) / $den;
    }

    private function spanMonths(Collection $set): float
    {
        return ($this->ts($set->last()->sample_date) - $this->ts($set->first()->sample_date)) / 86400.0 / 30.0;
    }

    private function ts($date): int
    {
        return $date instanceof \DateTimeInterface ? $date->getTimestamp() : (int) strtotime((string) $date);
    }
}
