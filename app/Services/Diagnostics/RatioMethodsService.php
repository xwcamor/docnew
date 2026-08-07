<?php

namespace App\Services\Diagnostics;

use App\Models\Chromatographical;

/**
 * RatioMethodsService — métodos clásicos de relaciones de gases: Rogers (3
 * relaciones) y Doernenburg (4). Portados EXACTOS de chromatographical.rb. Las
 * relaciones, rangos y fallas viven en ratio_methods.json (DATOS, editable); el
 * código solo divide y compara.
 *
 * Son métodos COMPLEMENTARIOS (segunda opinión). Solo tienen sentido con FALLA
 * ACTIVA (IEC 60599): la UI los gatea por active_fault, como Duval. Si una relación
 * no se puede calcular (denominador 0 o gas faltante) el método "no aplica".
 */
class RatioMethodsService
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? json_decode(
            file_get_contents(database_path('seeders/data/ratio_methods.json')),
            true
        );
    }

    /**
     * @return array{rogers:array,doernenburg:array}
     */
    public function evaluate(Chromatographical $s): array
    {
        return [
            'rogers'      => $this->run('rogers', $s),
            'doernenburg' => $this->run('doernenburg', $s),
        ];
    }

    /**
     * @return array{ratios:array<int,array{label:string,value:?float}>,fault:?string,complete:bool}
     */
    private function run(string $method, Chromatographical $s): array
    {
        $def = $this->cfg[$method];
        $vals = [];
        $complete = true;
        foreach ($def['ratios'] as $r) {
            $num = $s->{$r['num']};
            $den = $s->{$r['den']};
            if ($num === null || $den === null || (float) $den == 0.0) {
                $vals[$r['key']] = null;
                $complete = false;
            } else {
                $vals[$r['key']] = round((float) $num / (float) $den, 3);
            }
        }

        $fault = null;
        if ($complete) {
            foreach ($def['cases'] as $case) {
                if ($this->matches($case, $vals)) {
                    $fault = $case['fault'];
                    break;
                }
            }
        }

        $ratios = array_map(fn ($r) => ['label' => $r['label'], 'value' => $vals[$r['key']]], $def['ratios']);

        return ['ratios' => $ratios, 'fault' => $fault, 'complete' => $complete];
    }

    /** ¿La muestra cae en todos los rangos [min,max) del caso? */
    private function matches(array $case, array $vals): bool
    {
        foreach ($case as $key => $range) {
            if ($key === 'fault') {
                continue;
            }
            $v = $vals[$key] ?? null;
            if ($v === null) {
                return false;
            }
            [$min, $max] = $range;
            if (($min !== null && $v < $min) || ($max !== null && $v >= $max)) {
                return false;
            }
        }
        return true;
    }
}
