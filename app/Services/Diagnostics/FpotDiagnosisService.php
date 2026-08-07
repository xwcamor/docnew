<?php

namespace App\Services\Diagnostics;

use App\Models\ResultScale;
use App\Models\Test;

/**
 * FpotDiagnosisService — diagnóstico de una muestra de Factor de Potencia.
 *
 * Test de valor único: el factor de potencia del aislamiento (`num_fac`, en %)
 * se ubica directamente en la escala de fpot (result_scales, DATOS) para obtener
 * condición/color/rating del Índice de Salud. Sin conversión (a diferencia de
 * furanos): el valor medido entra tal cual. Sin medición → todo null.
 *
 * @return array{value:?float,condition:?string,color:?string,rating:?float}
 */
class FpotDiagnosisService
{
    public function evaluate(?float $value): array
    {
        $out = ['value' => null, 'condition' => null, 'color' => null, 'rating' => null];

        if ($value === null || $value < 0) {
            return $out;
        }

        $out['value'] = round($value, 3);

        $test = Test::where('code', 'fpot')->first();
        if ($test) {
            foreach (\App\Support\Diagnostics\ResultScaleResolver::bands($test->id) as $scale) {
                if ($scale->contains($value)) {
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
     * Traza del diagnóstico de fpot para el drawer "¿Por qué este resultado?":
     * el valor medido y la escala editable con la banda donde cae. No persiste.
     *
     * @return array{has_value:bool,...}
     */
    public function explain(?float $value): array
    {
        $out = [
            'has_value' => $value !== null && $value >= 0,
            'value'     => null,
            'scale'     => [],
            'condition' => null,
            'color'     => null,
            'rating'    => null,
        ];
        if (!$out['has_value']) {
            return $out;
        }

        $out['value'] = round($value, 3);

        $test = Test::where('code', 'fpot')->first();
        if ($test) {
            $bands = \App\Support\Diagnostics\ResultScaleResolver::bands($test->id);
            $matchedId = null;
            foreach ($bands as $s) {
                if ($s->contains($value)) {
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
}
