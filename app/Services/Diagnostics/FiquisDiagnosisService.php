<?php

namespace App\Services\Diagnostics;

use App\Models\ResultScale;
use App\Models\Test;

/**
 * FiquisDiagnosisService — diagnóstico fisicoquímico del aceite (norma IEEE C57.106).
 *
 * Cinco parámetros: rigidez dieléctrica (rig), tensión interfacial (ten), acidez
 * (acid), contenido de agua (wat) y factor de potencia (pot). Cada uno se puntúa
 * 1..4 (1=mejor) contra umbrales que dependen del tipo de aceite y de la clase de
 * tensión del transformador. Luego DGAF ponderado con PESO DINÁMICO (no castiga
 * parámetros faltantes): Σ(score×peso)/Σ(peso) → semáforo.
 *
 * Igual que cromas: el código es solo la fórmula; los umbrales/pesos viven en
 * database/seeders/data/fiquis_rules.json (editable). Si el aceite no tiene tabla,
 * o no hay ningún parámetro medido, devuelve null.
 */
class FiquisDiagnosisService
{
    private array $rules;
    private bool $fromDb;       // sin reglas custom → el semáforo se lee de result_scales
    private ?array $scaleCache = null;

    public function __construct(?array $rules = null)
    {
        $this->fromDb = $rules === null;
        $this->rules = $rules ?? self::loadFromDb();
    }

    /**
     * Arma la estructura de reglas (misma forma que el JSON viejo) desde las
     * tablas relacionales fiqui_params + fiqui_thresholds. Reconstruir la misma
     * forma deja intacta toda la lógica de evaluate()/explain() → el diagnóstico
     * es idéntico, solo cambia el ORIGEN (BD en vez de JSON). Si las tablas no
     * existen aún (sin migrar/sembrar) cae al archivo de fábrica.
     *
     * @return array{params:array,param_order:array,display:array,voltage_classes:array,tables:array}
     */
    public static function loadFromDb(): array
    {
        try {
            $paramsRows = \App\Models\FiquiParam::where('is_active', true)
                ->orderBy('display_order')->get();
            if ($paramsRows->isEmpty()) {
                return self::loadFromFile();
            }
        } catch (\Throwable) {
            return self::loadFromFile();
        }

        $params = []; $order = []; $display = [];
        foreach ($paramsRows as $p) {
            $meta = ['weight' => $p->weight, 'dir' => $p->dir, 'unit' => $p->unit,
                     'astm' => $p->astm, 'mode' => $p->mode];
            if ($p->tol !== null)  $meta['tol'] = $p->tol;
            if (!$p->is_core)      $meta['custom'] = true;
            $params[$p->key] = $meta;
            $display[] = $p->key;
            if ($p->scored) $order[] = $p->key;
        }

        // Híbrido por tenant: base GLOBAL (tenant_id null) + overlay del tenant
        // actual (auth). Cada celda (aceite × clase × param) del tenant pisa la
        // global; lo que no override, queda global. Sin tenant/override → idéntico.
        $tenantId = null;
        try {
            $tenantId = auth()->user()?->tenant_id;
        } catch (\Throwable) {
            // sin auth (consola/cola) → global
        }

        $tables = [];
        // Una celda es o un LÍMITE ÚNICO [t1] o una gradación completa
        // [t1,t2,t3]. Media gradación (t3 sin t2) no significa nada y haría que
        // el motor leyera t1 como si fuera el límite de aceptación, que en la
        // dirección "mayor = mejor" es justo el extremo opuesto.
        $apply = function ($t) use (&$tables) {
            $vals = [$t->t1];
            if ($t->t2 !== null && $t->t3 !== null) {
                $vals[] = $t->t2;
                $vals[] = $t->t3;
            }
            $tables[$t->oil_code][$t->voltage_class][$t->param_key] = $vals;
        };
        foreach (\App\Models\FiquiThreshold::whereNull('tenant_id')->get() as $t) {
            $apply($t);
        }
        if ($tenantId !== null) {
            foreach (\App\Models\FiquiThreshold::where('tenant_id', $tenantId)->get() as $t) {
                $apply($t);
            }
        }

        // El JSON de fábrica (loadFromFile) es la fuente de varios datos no
        // tabularizados; lo leemos una vez y reusamos.
        $factory = self::loadFromFile();

        return [
            'params'          => $params,
            'param_order'     => $order,
            'display'         => $display,
            // Cortes de clase de tensión (IEEE C57.106). Viven en
            // fiquis_rules.json (DATO editable), no clavados. El fallback solo
            // aplica si el JSON no los trae.
            'voltage_classes' => $factory['voltage_classes'] ?? ['low_max' => 69, 'mid_max' => 230],
            'tables'          => $tables,
            // Pares de métodos que miden la misma propiedad (rigidez D1816/D877,
            // factor de potencia 25/100 °C). No es calibración editable por el
            // super, es la relación entre dos columnas: vive en el JSON de
            // fábrica y no necesita tabla ni migración.
            'substitutions'   => $factory['substitutions'] ?? [],
            // Respaldo del semáforo (el primario sigue siendo result_scales,
            // editable en la UI). Solo se usa si result_scales está vacío.
            'semaphore'       => $factory['semaphore'] ?? [],
        ];
    }

    /** Fallback de fábrica: el archivo JSON (antes de migrar/sembrar las tablas). */
    private static function loadFromFile(): array
    {
        $path = database_path('seeders/data/fiquis_rules.json');
        return is_file($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];
    }

    /**
     * Bandas del semáforo: desde result_scales (editables en la UI de reglas) si
     * existen; sino, el 'semaphore' del JSON (fallback / tests con reglas custom).
     */
    private function semaphoreScale(): array
    {
        if ($this->scaleCache !== null) {
            return $this->scaleCache;
        }
        if ($this->fromDb) {
            try {
                $testId = Test::where('code', 'fiquis')->value('id');
                $rows = $testId ? \App\Support\Diagnostics\ResultScaleResolver::bands($testId) : collect();
                if ($rows->isNotEmpty()) {
                    return $this->scaleCache = $rows->map(fn ($s) => [
                        'max'       => $s->score_to === null ? null : (float) $s->score_to,
                        'condition' => $s->condition_label,
                        'color'     => $s->color,
                        'rating'    => $s->hi_rating === null ? null : (int) $s->hi_rating,
                    ])->all();
                }
            } catch (\Throwable) {
                // Sin BD/tabla (ej. tests unitarios puros) → semáforo del JSON.
            }
        }
        return $this->scaleCache = $this->rules['semaphore'];
    }

    /**
     * @param array<string,float|null> $values  claves rig/ten/acid/wat/pot (ppm/kV/…)
     * @return array{score:?float,condition:?string,color:?string,rating:?float,class:?string,components:array}|null
     */
    public function evaluate(?string $oilCode, ?float $voltageKv, array $values): ?array
    {
        $tablesForOil = $this->rules['tables'][$oilCode] ?? null;
        if (!$tablesForOil) {
            return null; // aceite sin tabla fiquis (ej. ésteres, o aceite custom)
        }

        $class = $this->voltageClass($oilCode, $voltageKv);
        $table = $tablesForOil[$class] ?? null;
        if (!$table) {
            return null;
        }

        $sumWeighted = 0.0;
        $sumWeight = 0.0;
        $components = [];

        foreach ($this->rules['param_order'] as $key) {
            $m = $this->measurementFor($key, $values, $table);
            if ($m === null) {
                continue; // peso dinámico: propiedad no medida (ni por el método alterno)
            }
            // El peso es el de la PROPIEDAD, no el del método: si el laboratorio
            // reportó D877 en lugar de D1816, la rigidez sigue pesando 3.
            $weight = (float) $this->rules['params'][$key]['weight'];
            $score  = $m['score'];

            $sumWeighted += $score * $weight;
            $sumWeight += $weight;

            // Se indexa por el método REALMENTE medido para que las etiquetas y
            // los límites que arma el front salgan de la norma que se aplicó.
            $components[$m['key']] = ['value' => $m['value'], 'score' => $score, 'weight' => $weight];
            if ($m['key'] !== $key) {
                $components[$m['key']]['substitutes'] = $key;
            }
        }

        if ($sumWeight <= 0) {
            return null; // sin parámetros medidos
        }

        $dgaf = $sumWeighted / $sumWeight;
        $scale = $this->semaphore($dgaf);

        return [
            'score'      => round($dgaf, 2),
            'condition'  => $scale['condition'],
            'color'      => $scale['color'],
            'rating'     => (float) $scale['rating'],
            'class'      => $class,
            'components' => $components,
        ];
    }

    /**
     * Bandas de límite por parámetro para un aceite + tensión, en orden ascendente
     * de valor, con severidad 0..1 (0=mejor, 1=peor). Alimenta las franjas de las
     * tendencias fisicoquímicas. Vacío si el aceite no tiene tabla.
     *
     * @return array<string,array<int,array{from:float,to:?float,sev:float}>>
     */
    public function limitsFor(?string $oilCode, ?float $voltageKv): array
    {
        $tablesForOil = $this->rules['tables'][$oilCode] ?? null;
        if (!$tablesForOil) {
            return [];
        }
        $table = $tablesForOil[$this->voltageClass($oilCode, $voltageKv)] ?? null;
        if (!$table) {
            return [];
        }

        $out = [];
        foreach ($this->rules['params'] as $key => $meta) {
            $th = $table[$key] ?? null;
            if ($th === null) {
                continue;
            }
            $lower = str_starts_with($meta['dir'], 'lower');

            // MODO 'limit': umbral único + tolerancia → 3 bandas verde/amarillo/rojo
            // (igual que ieee_color_num_pot2 / num_rig2 del viejo).
            //
            // Si al método alterno se le cargaron los tres umbrales, deja de ser
            // un límite pelado: se colorea con las 4 bandas como cualquier otro,
            // que es lo mismo con lo que puntúa cuando sustituye.
            if (($meta['mode'] ?? 'scored') === 'limit' && count($th) < 3) {
                $l = (float) $th[0];
                $tol = (float) ($meta['tol'] ?? 0.1);
                $out[$key] = $lower
                    ? [ // menor = mejor: verde < l·(1-tol) · amarillo hasta l · rojo ≥ l
                        ['from' => 0.0,            'to' => $l * (1 - $tol), 'sev' => 0.0],
                        ['from' => $l * (1 - $tol), 'to' => $l,              'sev' => 0.5],
                        ['from' => $l,              'to' => null,            'sev' => 1.0],
                    ]
                    : [ // mayor = mejor: rojo < l · amarillo hasta l·(1+tol) · verde ≥
                        ['from' => 0.0,            'to' => $l,              'sev' => 1.0],
                        ['from' => $l,              'to' => $l * (1 + $tol), 'sev' => 0.5],
                        ['from' => $l * (1 + $tol), 'to' => null,            'sev' => 0.0],
                    ];
                continue;
            }

            // MODO 'scored': 4 bandas [t1,t2,t3] → severidad 0..1.
            $e = $th;
            sort($e); // umbrales ascendentes [e1,e2,e3]
            $scores = $lower ? [1, 2, 3, 4] : [4, 3, 2, 1];
            $edges = [0.0, $e[0], $e[1], $e[2], null];
            $bands = [];
            for ($i = 0; $i < 4; $i++) {
                $bands[] = [
                    'from' => (float) $edges[$i],
                    'to'   => $edges[$i + 1] === null ? null : (float) $edges[$i + 1],
                    'sev'  => (float) (($scores[$i] - 1) / 3),
                ];
            }
            $out[$key] = $bands;
        }
        return $out;
    }

    /**
     * Columnas a mostrar para un aceite + tensión, en orden de presentación, con su
     * norma (astm), unidad y modo. Con tabla → solo los parámetros que aplican a ese
     * aceite; sin aceite/tabla → todos (para poder registrar datos igual). El front
     * arma la grilla desde aquí: agregar un campo nuevo = una fila en params/tables,
     * sin tocar el componente.
     *
     * @return array<int,array{key:string,astm:?string,unit:?string,mode:string}>
     */
    public function columnsFor(?string $oilCode, ?float $voltageKv): array
    {
        $order = $this->rules['display'] ?? array_keys($this->rules['params']);
        $tablesForOil = $this->rules['tables'][$oilCode] ?? null;
        $table = $tablesForOil ? ($tablesForOil[$this->voltageClass($oilCode, $voltageKv)] ?? null) : null;

        $cols = [];
        foreach ($order as $key) {
            $meta = $this->rules['params'][$key] ?? null;
            if (!$meta) {
                continue;
            }
            if ($table !== null && !isset($table[$key])) {
                continue; // ese aceite no mide este parámetro
            }
            $cols[] = [
                'key'  => $key,
                'astm' => $meta['astm'] ?? null,
                'unit' => $meta['unit'] ?? null,
                'mode' => $meta['mode'] ?? 'scored',
            ];
        }
        return $cols;
    }

    /**
     * Traza del diagnóstico fisicoquímico para el drawer "¿Por qué este resultado?":
     * la clase de tensión usada, el score 1..4 de cada parámetro contra sus umbrales
     * (con dirección y peso), la suma ponderada, el DGAF y el semáforo con la banda
     * donde cae. Misma matemática que evaluate(), sin persistir.
     *
     * @param array<string,float|null> $values
     * @return array{has_rules:bool,...}
     */
    public function explain(?string $oilCode, ?float $voltageKv, array $values): array
    {
        $tablesForOil = $this->rules['tables'][$oilCode] ?? null;
        if (!$tablesForOil) {
            return ['has_rules' => false];
        }
        $class = $this->voltageClass($oilCode, $voltageKv);
        $table = $tablesForOil[$class] ?? null;
        if (!$table) {
            return ['has_rules' => false];
        }

        $sumWeighted = 0.0;
        $sumWeight = 0.0;
        $params = [];

        $sustituidos = [];   // método alterno que ya ocupó el lugar del principal

        foreach ($this->rules['param_order'] as $key) {
            $meta = $this->rules['params'][$key];
            $m    = $this->measurementFor($key, $values, $table);
            $row = [
                'param'        => $key,
                'unit'         => $meta['unit'] ?? null,
                'dir'          => $meta['dir'],
                'weight'       => (float) $meta['weight'],
                'scores'       => true,
                'thresholds'   => $table[$key] ?? null,
                'value'        => isset($values[$key]) ? (float) $values[$key] : null,
                'score'        => null,
                'contribution' => null,
                // Método alterno usado en lugar del principal (null = el normal).
                'measured_as'  => null,
            ];
            if ($m !== null) {
                $score = $m['score'];
                $sumWeighted += $score * $row['weight'];
                $sumWeight   += $row['weight'];
                $row['score']        = $score;
                $row['contribution'] = round($score * $row['weight'], 2);
                $row['value']        = $m['value'];
                $row['thresholds']   = $m['thresholds'];
                $row['unit']         = $this->rules['params'][$m['key']]['unit'] ?? $row['unit'];
                if ($m['key'] !== $key) {
                    $row['measured_as'] = $m['key'];
                    $sustituidos[$m['key']] = true;
                }
            }
            $params[] = $row;
        }

        // Parámetros de REFERENCIA (modo 'limit': rigidez D877, factor de
        // potencia a 100 °C) QUE SE MIDIERON. Se muestran con su umbral y su
        // estado, pero NO entran en el promedio: miden la MISMA propiedad que un
        // parámetro que ya puntúa (las dos rigideces; el factor de potencia a
        // dos temperaturas), así que sumarlos contaría dos veces lo mismo y
        // diluiría acidez, agua y tensión interfacial. Igual que el sistema
        // viejo, pero ahora visibles en la traza en vez de invisibles.
        foreach (($this->rules['display'] ?? []) as $key) {
            if (in_array($key, $this->rules['param_order'], true)) {
                continue;
            }
            // Si ya sustituyó al principal está arriba, puntuando: repetirlo acá
            // como "no puntúa" diría lo contrario de lo que pasó.
            if (isset($sustituidos[$key])) {
                continue;
            }
            $meta = $this->rules['params'][$key] ?? null;
            $thresholds = $table[$key] ?? null;
            if (!$meta || $thresholds === null) {
                continue;
            }
            // Sin valor no hay nada que mostrar: una fila "No medido · no
            // puntúa" no dice nada del diagnóstico y solo alarga la traza. El
            // bloque entero desaparece si ningún método alterno se midió.
            if (($values[$key] ?? null) === null) {
                continue;
            }

            $lower = str_starts_with($meta['dir'], 'lower');
            // El límite de la norma es el borde de fuera-de-norma: el mayor si
            // menos es mejor, el menor si más es mejor. Con un umbral único los
            // dos coinciden; con la gradación completa cargada, NO (t1 sería el
            // extremo opuesto).
            $limit = $lower ? (float) max($thresholds) : (float) min($thresholds);
            $tol   = (float) ($meta['tol'] ?? 0.1);
            $value = $values[$key] ?? null;

            // Mismo criterio de bandas que limitsFor(): la tolerancia ±10% es
            // solo la franja ámbar de aviso; la norma es el límite pelado.
            $status = null;
            if ($value !== null) {
                $v = (float) $value;
                $status = $lower
                    ? ($v >= $limit ? 'out' : ($v >= $limit * (1 - $tol) ? 'near' : 'ok'))
                    : ($v <  $limit ? 'out' : ($v <  $limit * (1 + $tol) ? 'near' : 'ok'));
            }

            $params[] = [
                'param'        => $key,
                'unit'         => $meta['unit'] ?? null,
                'dir'          => $meta['dir'],
                'weight'       => null,
                'scores'       => false,
                'thresholds'   => $thresholds,
                'limit'        => $limit,
                'value'        => $value === null ? null : (float) $value,
                'status'       => $status,
                'score'        => null,
                'contribution' => null,
            ];
        }

        $dgaf  = $sumWeight > 0 ? $sumWeighted / $sumWeight : null;
        $scale = $dgaf !== null ? $this->semaphore($dgaf) : null;

        return [
            'has_rules'        => true,
            'has_data'         => $sumWeight > 0,
            'class'            => $class,
            'params'           => $params,
            'sum_score_weight' => round($sumWeighted, 2),
            'sum_weight'       => round($sumWeight, 2),
            'dgaf'             => $dgaf === null ? null : round($dgaf, 4),
            'semaphore'        => $this->semaphoreBands($dgaf),
            'condition'        => $scale['condition'] ?? null,
            'color'            => $scale['color'] ?? null,
            'rating'           => isset($scale['rating']) ? (float) $scale['rating'] : null,
        ];
    }

    /** El semáforo como bandas from–to con la que cae el DGAF marcada. */
    private function semaphoreBands(?float $dgaf): array
    {
        $matchedIdx = null;
        if ($dgaf !== null) {
            foreach ($this->semaphoreScale() as $i => $band) {
                if ($band['max'] === null || $dgaf < $band['max']) {
                    $matchedIdx = $i;
                    break;
                }
            }
        }
        $out = [];
        $prev = 0.0;
        foreach ($this->semaphoreScale() as $i => $band) {
            $out[] = [
                'from'      => $prev,
                'to'        => $band['max'],
                'condition' => $band['condition'],
                'color'     => $band['color'],
                'rating'    => $band['rating'],
                'matched'   => $i === $matchedIdx,
            ];
            $prev = $band['max'] ?? $prev;
        }
        return $out;
    }

    /** Clase de tensión: silicona no segmenta ('all'); resto por kV (default low si no hay tensión). */
    private function voltageClass(string $oilCode, ?float $voltageKv): string
    {
        if (isset($this->rules['tables'][$oilCode]['all'])) {
            return 'all';
        }
        if ($voltageKv === null) {
            return 'low';
        }
        $lowMax = (float) $this->rules['voltage_classes']['low_max'];
        $midMax = (float) $this->rules['voltage_classes']['mid_max'];
        if ($voltageKv <= $lowMax) {
            return 'low';
        }
        return $voltageKv < $midMax ? 'mid' : 'high';
    }

    /**
     * Medición con la que se puntúa una propiedad: el método principal si el
     * laboratorio lo reportó, y si no, el método ALTERNO en su lugar.
     *
     * Rigidez dieléctrica y factor de potencia se miden por dos métodos cada uno
     * (D1816/D877 · 25 °C/100 °C). El principal es el que puntúa; el alterno se
     * lista como referencia. Pero cuando el informe SOLO trae el alterno, dejar
     * la propiedad fuera del promedio la volvía invisible: un aceite con la
     * rigidez por el piso medida en D877 salía con el mismo índice que uno al
     * que simplemente no le midieron la rigidez.
     *
     * Sustituye, NO suma: una propiedad ocupa un solo lugar en el índice, con el
     * método que haya. Sumar los dos contaría dos veces lo mismo y diluiría
     * acidez, agua y tensión interfacial (ver docs/origen-ruby/diseno/
     * FIQUIS_auditoria.md).
     *
     * @return array{key:string,value:float,score:int,thresholds:array}|null
     */
    private function measurementFor(string $key, array $values, array $table): ?array
    {
        $value = $values[$key] ?? null;
        $th    = $table[$key] ?? null;
        $meta  = $this->rules['params'][$key] ?? null;
        if ($value !== null && $th !== null && $meta !== null) {
            return [
                'key'        => $key,
                'value'      => (float) $value,
                'score'      => $this->scoreFor((float) $value, $th, $meta['dir']),
                'thresholds' => $th,
            ];
        }

        $alt = $this->rules['substitutions'][$key] ?? null;
        if ($alt === null || ($values[$alt] ?? null) === null) {
            return null;
        }
        $altMeta = $this->rules['params'][$alt] ?? null;
        $altTh   = $table[$alt] ?? null;
        if (!$altMeta || !$meta || !$altTh) {
            return null;
        }
        // Los dos métodos tienen que mirar en la misma dirección; si no, no
        // estarían midiendo la misma propiedad.
        if (!$this->sameDirection($meta['dir'], $altMeta['dir'])) {
            return null;
        }

        $v = (float) $values[$alt];

        // Con la gradación completa cargada, el alterno puntúa como cualquier
        // otro parámetro. Es lo que pasa si el laboratorio entrega sus cuatro
        // niveles y el super los carga en el editor de umbrales.
        if (count($altTh) >= 3) {
            $tres = array_slice($altTh, 0, 3);
            return ['key' => $alt, 'value' => $v, 'score' => $this->scoreFor($v, $tres, $altMeta['dir']), 'thresholds' => $tres];
        }

        // Caso normal: la norma publica UN límite de aceptación, no una
        // gradación. Se puntúa con las mismas tres bandas con las que ya se
        // colorea la celda (límite ± tolerancia), para que el color y el score
        // nunca digan cosas distintas.
        return [
            'key'        => $alt,
            'value'      => $v,
            'score'      => $this->scoreForLimit($v, (float) $altTh[0], (float) ($altMeta['tol'] ?? 0.1), str_starts_with($altMeta['dir'], 'lower')),
            'thresholds' => $altTh,
        ];
    }

    private function sameDirection(string $a, string $b): bool
    {
        return str_starts_with($a, 'lower') === str_starts_with($b, 'lower');
    }

    /**
     * Score de un parámetro que solo tiene límite de aceptación (D877, factor de
     * potencia a 100 °C). Tres salidas, las mismas del semáforo de la celda:
     *
     *   cumple            → 1  (el mejor)
     *   dentro de la tolerancia, pegado al límite → 3
     *   fuera de norma    → 4  (el peor)
     *
     * No hay score 2 y es a propósito: la norma no gradúa, así que inventar un
     * cuarto nivel sería ponerle al dato una precisión que no tiene. Los cortes
     * son EXACTAMENTE los de limitsFor()/explain(): si la celda está verde el
     * parámetro aporta 1, si está ámbar aporta 3 y si está roja aporta 4. Que el
     * color y el score se contradigan sería peor que no graduar.
     */
    private function scoreForLimit(float $v, float $limit, float $tol, bool $lower): int
    {
        if ($lower) {
            return $v >= $limit ? 4 : ($v >= $limit * (1 - $tol) ? 3 : 1);
        }
        return $v < $limit ? 4 : ($v < $limit * (1 + $tol) ? 3 : 1);
    }

    /** Score 1..4 según dirección y umbrales [t1,t2,t3]. */
    private function scoreFor(float $v, array $t, string $dir): int
    {
        [$t1, $t2, $t3] = $t;
        return match ($dir) {
            // Más alto = mejor (rigidez, tensión interfacial).
            'higher'       => $v >= $t1 ? 1 : ($v > $t2 ? 2 : ($v > $t3 ? 3 : 4)),
            // Más bajo = mejor, borde superior inclusivo (agua, factor de potencia).
            'lower'        => $v <= $t1 ? 1 : ($v <= $t2 ? 2 : ($v <= $t3 ? 3 : 4)),
            // Más bajo = mejor, borde superior estricto (acidez: >=t3 → peor).
            'lower_strict' => $v <= $t1 ? 1 : ($v <= $t2 ? 2 : ($v < $t3 ? 3 : 4)),
            default        => 4,
        };
    }

    private function semaphore(float $dgaf): array
    {
        foreach ($this->semaphoreScale() as $band) {
            if ($band['max'] === null || $dgaf < $band['max']) {
                return $band;
            }
        }
        return end($this->semaphoreScale());
    }
}
