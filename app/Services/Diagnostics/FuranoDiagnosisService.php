<?php

namespace App\Services\Diagnostics;

use App\Models\ResultScale;
use App\Models\Test;

/**
 * FuranoDiagnosisService — diagnóstico de una muestra de furanos.
 *
 * El 2-furfuraldehído (`fal`, en ppb) es el que diagnostica: convertido a ppm
 * (÷1000, FÓRMULA) se ubica en la escala de furanos (result_scales, DATOS) para
 * obtener condición/color/rating del Índice de Salud. Además estima el grado de
 * polimerización del papel (DP) con la fórmula de Chendong:
 *
 *     DP = (1.51 − log10(FAL_ppm)) / 0.0035
 *
 * (portada de furano.rb `shendong`). Sin medición de fal → todo null.
 *
 * @return array{ppm:?float,dp:?int,condition:?string,color:?string,rating:?float}
 */
class FuranoDiagnosisService
{
    /** DP de papel nuevo y de fin de vida (IEEE C57.91 / Emsley). FÓRMULA. */
    private const DP_NEW = 1000;
    private const DP_EOL = 200;

    /**
     * % de vida mecánica del papel ya consumida, a partir del DP estimado.
     * DP_NEW (1000) = 0% consumido · DP_EOL (200) = 100% (fin de vida).
     * Lineal y acotado a 0..100. Null si no hay DP.
     */
    public function paperLifePercent(?int $dp): ?int
    {
        if ($dp === null) {
            return null;
        }
        $pct = (self::DP_NEW - $dp) / (self::DP_NEW - self::DP_EOL) * 100;

        return (int) round(max(0, min(100, $pct)));
    }

    public function evaluate(?float $falPpb): array
    {
        $out = ['ppm' => null, 'dp' => null, 'condition' => null, 'color' => null, 'rating' => null, 'life_percent' => null];

        if ($falPpb === null || $falPpb < 0) {
            return $out;
        }

        $ppm = $falPpb / 1000.0;
        $out['ppm'] = round($ppm, 5);

        // DP de Chendong (sólo si hay furano medible; log10(0) no existe).
        if ($ppm > 0) {
            $out['dp'] = max(0, (int) round((1.51 - log10($ppm)) / 0.0035));
            $out['life_percent'] = $this->paperLifePercent($out['dp']);
        }

        // Condición/color/rating desde la escala editable de furanos.
        $test = Test::where('code', 'furanos')->first();
        if ($test) {
            foreach (\App\Support\Diagnostics\ResultScaleResolver::bands($test->id) as $scale) {
                if ($scale->contains($ppm)) {
                    $out['condition'] = $scale->condition_label;
                    $out['color']     = $scale->color;
                    $out['rating']    = $scale->hi_rating === null ? null : (float) $scale->hi_rating;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Traza del diagnóstico de furanos para el drawer "¿Por qué este resultado?":
     * la conversión fal (ppb)→ppm, el DP de Chendong con su fórmula, y la escala
     * editable con la banda donde cae. No persiste.
     *
     * @return array{has_value:bool,...}
     */
    public function explain(?float $falPpb, ?string $paperType = null): array
    {
        $out = [
            'has_value' => $falPpb !== null && $falPpb >= 0,
            'fal_ppb'   => $falPpb === null ? null : (float) $falPpb,
            'ppm'       => null,
            'dp'        => null,
            'life_percent' => null,
            'scale'     => [],
            'condition' => null,
            'color'     => null,
            'rating'    => null,
            // Tipo de papel del trafo: con 'upgraded' (térmicamente mejorado) el
            // 2-FAL y el DP de Chendong SUBESTIMAN la degradación (genera menos
            // furano por diseño). No se recalculan bandas — solo se avisa.
            'paper_type'    => $paperType,
            'paper_warning' => $paperType === 'upgraded',
            // Fuente/referencia: estado y URL desde config; las citas se traducen
            // (i18n) para que el drawer respete el idioma activo.
            'reference' => $this->reference(),
        ];
        if (!$out['has_value']) {
            return $out;
        }

        $ppm = $falPpb / 1000.0;
        $out['ppm'] = round($ppm, 5);
        if ($ppm > 0) {
            $out['dp'] = max(0, (int) round((1.51 - log10($ppm)) / 0.0035));
            $out['life_percent'] = $this->paperLifePercent($out['dp']);
        }

        $test = Test::where('code', 'furanos')->first();
        if ($test) {
            $bands = \App\Support\Diagnostics\ResultScaleResolver::bands($test->id);
            $matchedId = null;
            foreach ($bands as $s) {
                if ($s->contains($ppm)) {
                    $matchedId = $s->id;
                    $out['condition'] = $s->condition_label;
                    $out['color']     = $s->color;
                    $out['rating']    = $s->hi_rating === null ? null : (float) $s->hi_rating;
                    break;
                }
            }
            $out['scale'] = $bands->map(fn ($s) => [
                'from'      => $s->score_from === null ? null : (float) $s->score_from,
                'to'        => $s->score_to === null ? null : (float) $s->score_to,
                'condition' => $s->condition_label,
                'color'     => $s->color,
                'rating'    => $s->hi_rating === null ? null : (float) $s->hi_rating,
                'matched'   => $s->id === $matchedId,
            ])->all();
        }

        return $out;
    }

    /**
     * Referencia del diagnóstico para el drawer: estado + URL desde config (no
     * dependen del idioma) y las citas traducidas vía i18n, para que el texto
     * salga en el idioma activo del usuario.
     */
    private function reference(): array
    {
        $cfg = (array) config('diagnostic_sources.furanos', []);

        return [
            'formula' => [
                'citation' => __('furanos.sources.formula_citation'),
                'url'      => $cfg['formula']['url'] ?? null,
                'status'   => $cfg['formula']['status'] ?? 'unconfirmed',
            ],
            'scale' => [
                'citation' => __('furanos.sources.scale_citation'),
                'url'      => $cfg['scale']['url'] ?? null,
                'status'   => $cfg['scale']['status'] ?? 'unconfirmed',
            ],
        ];
    }
}
