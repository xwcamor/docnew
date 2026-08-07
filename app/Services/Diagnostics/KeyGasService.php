<?php

namespace App\Services\Diagnostics;

use App\Models\Chromatographical;

/**
 * KeyGasService — método del Gas Clave (IEEE C57.104).
 *
 * Identifica el tipo de falla (arco, corona/PD, sobrecalentamiento del aceite o de
 * la celulosa) comparando el PERFIL de gases medido contra las firmas típicas de
 * cada falla. A diferencia del sistema viejo (donde el usuario elegía la falla a
 * mano), aquí se CALCULA: se normalizan los 6 gases clave a % y se elige la firma
 * más cercana (distancia L1). Las firmas viven en key_gas_signatures.json (editable).
 *
 * "No es para todos los casos": si no hay gases (suma 0) devuelve null. La confianza
 * baja avisa cuando el perfil no se parece claramente a ninguna firma.
 */
class KeyGasService
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? json_decode(
            file_get_contents(database_path('seeders/data/key_gas_signatures.json')),
            true
        );
    }

    /**
     * @return array{fault:string,key_gas:string,confidence:int,measured:array,signature:array}|null
     */
    public function evaluate(Chromatographical $s): ?array
    {
        $gasKeys = $this->cfg['gases'];
        $values = [];
        $total = 0.0;
        foreach ($gasKeys as $g) {
            $v = (float) ($s->{$g} ?? 0);
            $values[$g] = $v;
            $total += $v;
        }
        if ($total <= 0) {
            return null;
        }

        // Perfil medido en % del total de los 6 gases clave.
        $measured = [];
        foreach ($gasKeys as $g) {
            $measured[$g] = round($values[$g] / $total * 100, 1);
        }

        // Falla con la firma más cercana (distancia L1 sobre los %).
        $best = null;
        $bestDist = INF;
        foreach ($this->cfg['faults'] as $fault) {
            $dist = 0.0;
            foreach ($gasKeys as $g) {
                $dist += abs($measured[$g] - (float) $fault['sig'][$g]);
            }
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $fault;
            }
        }

        // Confianza: 1 - dist/200 (200 = máxima distancia entre dos perfiles de %).
        $confidence = (int) round(max(0.0, 1 - $bestDist / 200) * 100);

        return [
            'fault'      => $best['code'],
            'key_gas'    => $best['key'],
            'confidence' => $confidence,
            'measured'   => $measured,
            'signature'  => array_map(fn ($v) => (float) $v, $best['sig']),
        ];
    }
}
