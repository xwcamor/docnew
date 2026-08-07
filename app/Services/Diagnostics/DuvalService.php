<?php

namespace App\Services\Diagnostics;

use App\Models\Chromatographical;

/**
 * DuvalService — método gráfico de Duval (DGA, aceite mineral).
 *
 * Ubica el punto de un ensayo cromatográfico en los triángulos (1, 4, 5) y
 * pentágonos (1, 2, combinado) de Duval y devuelve la zona de falla en que cae
 * (point-in-polygon). Portado del sistema Ruby viejo SIN su práctica de cargar el
 * diagnóstico a mano: aquí se CALCULA desde los ppm. Las zonas (polígonos) viven en
 * database/seeders/data/duval_zones.json (editable); el código solo proyecta y
 * resuelve geometría.
 *
 * Triángulos: vértices en baricéntrico [p1,p2]; proj(p1,p2)=[p2+0.5*p1, 0.866*p1].
 * Pentágonos: vértices en coordenadas nativas; el punto es el centroide del polígono
 * que forman los 5 gases sobre sus ejes. Regla de visibilidad: T1 siempre; T4/T5 solo
 * para ciertas zonas de T1 (metodología Duval).
 */
class DuvalService
{
    private const COS60 = 0.5;
    private const SIN60 = 0.8660254037844386;

    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? json_decode(
            file_get_contents(database_path('seeders/data/duval_zones.json')),
            true
        );
    }

    /**
     * Diagnóstico Duval de un ensayo. Null en triángulo/pentágono si no hay gases
     * suficientes (suma 0) para ubicar el punto.
     *
     * @return array{triangles:array,pentagon:?array}
     */
    public function evaluate(Chromatographical $s): array
    {
        $gas = [
            'h2'   => (float) ($s->h2 ?? 0),
            'ch4'  => (float) ($s->ch4 ?? 0),
            'c2h4' => (float) ($s->c2h4 ?? 0),
            'c2h6' => (float) ($s->c2h6 ?? 0),
            'c2h2' => (float) ($s->c2h2 ?? 0),
        ];

        return $this->evaluateGases($gas, $s->transformer?->oilType?->code);
    }

    /**
     * Igual que evaluate() pero con gases crudos + código de aceite (sin modelo).
     * Lo usa el calculador Duval standalone (Tools) para hacer live calculation.
     *
     * @param array{h2:float,ch4:float,c2h4:float,c2h6:float,c2h2:float} $gas
     * @return array{triangles:array,pentagon:?array}
     */
    public function evaluateGases(array $gas, ?string $oil = null): array
    {
        $gas = [
            'h2'   => (float) ($gas['h2']   ?? 0),
            'ch4'  => (float) ($gas['ch4']  ?? 0),
            'c2h4' => (float) ($gas['c2h4'] ?? 0),
            'c2h6' => (float) ($gas['c2h6'] ?? 0),
            'c2h2' => (float) ($gas['c2h2'] ?? 0),
        ];

        // Conmutador (LTC): Triángulo 2 standalone (zonas propias). No es el tanque.
        if ($oil === 'ltc') {
            $t2 = $this->triangleFromDef($this->cfg['triangle2'], $gas);
            if ($t2 !== null) {
                $t2['visible'] = true;
            }
            return ['triangles' => ['T2' => $t2], 'pentagon' => null];
        }

        // Aceites NO minerales usan el Triángulo 3 (otros cortes de %C2H4) y no
        // aplican T4/T5 ni pentágonos.
        if (isset($this->cfg['triangle3']['oils'][$oil])) {
            $t3 = $this->triangleFromDef($this->t3Def($oil), $gas);
            if ($t3 !== null) {
                $t3['visible'] = true;
            }
            return ['triangles' => ['T3' => $t3], 'pentagon' => null];
        }

        // ── Triángulos (aceite mineral) ────────────────────────────────
        $triangles = ['T1' => null, 'T4' => null, 'T5' => null];
        foreach (['T1', 'T4', 'T5'] as $key) {
            $triangles[$key] = $this->triangleFromDef($this->cfg['triangles'][$key], $gas);
        }
        $t1Zone = $triangles['T1']['zone'] ?? null;

        // Visibilidad: T1 siempre (si pudo ubicarse); T4/T5 según la zona de T1.
        // Un triángulo que no pudo ubicarse (sin gases) queda en null.
        if ($triangles['T1'] !== null) {
            $triangles['T1']['visible'] = true;
        }
        if ($triangles['T4'] !== null) {
            $triangles['T4']['visible'] = in_array($t1Zone, $this->cfg['visibility']['T4_if_T1_in'], true);
        }
        if ($triangles['T5'] !== null) {
            $triangles['T5']['visible'] = in_array($t1Zone, $this->cfg['visibility']['T5_if_T1_in'], true);
        }

        return [
            'triangles' => $triangles,
            'pentagon'  => $this->pentagon($gas),
        ];
    }

    /**
     * Geometría (zonas ya proyectadas) para que el front dibuje el fondo. Según el
     * aceite: mineral → T1/T4/T5 + pentágonos; no mineral → solo Triángulo 3.
     */
    public function geometry(?string $oilCode = null): array
    {
        if ($oilCode === 'ltc') {
            return [
                'triangles' => ['T2' => $this->projectZones($this->cfg['triangle2']['zones'])],
                'pentagons' => [],
            ];
        }

        if (isset($this->cfg['triangle3']['oils'][$oilCode])) {
            return [
                'triangles' => ['T3' => $this->projectZones($this->t3Def($oilCode)['zones'])],
                'pentagons' => [],
            ];
        }

        $tris = [];
        foreach (['T1', 'T4', 'T5'] as $key) {
            $tris[$key] = $this->projectZones($this->cfg['triangles'][$key]['zones']);
        }
        $pents = [];
        foreach (['P1', 'P2', 'combine'] as $key) {
            $pents[$key] = array_map(fn ($z) => ['code' => $z['code'], 'pts' => $z['pts']], $this->cfg['pentagons'][$key]['zones']);
        }
        return ['triangles' => $tris, 'pentagons' => $pents];
    }

    /** Proyecta los `pts` baricéntricos de una lista de zonas a cartesiano. */
    private function projectZones(array $zones): array
    {
        return array_map(fn ($z) => [
            'code' => $z['code'],
            'pts'  => array_map(fn ($p) => $this->projTriangle($p[0], $p[1]), $z['pts']),
        ], $zones);
    }

    /**
     * Definición del Triángulo 3 para un aceite no mineral: mismas zonas que el T1
     * pero con los cortes de %C2H4 (D1/D2, T1/T2, T2/T3) del aceite. Reusa los
     * polígonos de dibujo del T1.
     */
    private function t3Def(string $oil): array
    {
        $thr = $this->cfg['triangle3']['oils'][$oil];
        [$d, $a, $b] = [$thr['d1d2'], $thr['t1t2'], $thr['t2t3']];
        $pts = [];
        foreach ($this->cfg['triangles']['T1']['zones'] as $z) {
            $pts[$z['code']] = $z['pts'];
        }
        $zone = fn ($code, $cond) => ['code' => $code, 'cond' => $cond, 'pts' => $pts[$code]];

        return [
            'gases' => $this->cfg['triangle3']['gases'],
            'zones' => [
                $zone('PD', [['ch4', '>=', 98]]),
                $zone('D1', [['c2h4', '<', $d], ['c2h2', '>=', 13]]),
                $zone('D2', [['c2h4', '>=', $d], ['c2h4', '<', 40], ['c2h2', '>=', 13]]),
                $zone('T3', [['c2h2', '<', 15], ['c2h4', '>=', $b]]),
                $zone('T2', [['c2h2', '<', 4], ['c2h4', '>=', $a], ['c2h4', '<', $b]]),
                $zone('T1', [['c2h2', '<', 4], ['c2h4', '<', $a]]),
                $zone('DT', []),
            ],
        ];
    }

    // ── Triángulo ──────────────────────────────────────────────────────
    private function triangleFromDef(array $def, array $gas): ?array
    {
        [$g1, $g2, $g3] = $def['gases'];
        $sum = $gas[$g1] + $gas[$g2] + $gas[$g3];
        if ($sum <= 0) {
            return null;
        }
        $f1 = $gas[$g1] / $sum;
        $f2 = $gas[$g2] / $sum;
        $f3 = $gas[$g3] / $sum;
        $pt = $this->projTriangle($f1, $f2);
        $pct = [$g1 => $f1 * 100, $g2 => $f2 * 100, $g3 => $f3 * 100];

        // Si el triángulo trae `classify` (inecuaciones canónicas, p.ej. T4/T5),
        // la zona sale de ahí (cobertura completa); `zones` queda solo para dibujar.
        $clsDef = isset($def['classify']) ? ['zones' => $def['classify']] : $def;

        return [
            'zone'  => $this->classifyTriangle($clsDef, $pct, $pt),
            'point' => $pt,
            'pct'   => array_map(fn ($v) => round($v), $pct),
        ];
    }

    /**
     * Zona de un triángulo. Si las zonas traen `cond` (inecuaciones de % — la regla
     * canónica IEC 60599), gana el PRIMER match (DT = `cond` vacío = resto). Si no,
     * cae al point-in-polygon sobre los `pts` (último match, como el Ruby viejo).
     */
    private function classifyTriangle(array $def, array $pct, array $pt): ?string
    {
        $byCond = isset($def['zones'][0]['cond']);
        $zone = null;
        foreach ($def['zones'] as $z) {
            if ($byCond) {
                if ($this->condsHold($z['cond'], $pct)) {
                    return $z['code'];
                }
                continue;
            }
            if ($this->inPolygon($pt, array_map(fn ($p) => $this->projTriangle($p[0], $p[1]), $z['pts']))) {
                $zone = $z['code'];
            }
        }
        return $zone;
    }

    /** Todas las inecuaciones [gas, op, valor] se cumplen sobre los porcentajes. */
    private function condsHold(array $cond, array $pct): bool
    {
        foreach ($cond as [$gas, $op, $val]) {
            $v = $pct[$gas] ?? 0.0;
            $ok = match ($op) {
                '>=' => $v >= $val,
                '>'  => $v > $val,
                '<=' => $v <= $val,
                '<'  => $v < $val,
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** Baricéntrico [p1,p2] → cartesiano (calcRealPos del Ruby, escala unitaria). */
    private function projTriangle(float $p1, float $p2): array
    {
        return [$p2 + self::COS60 * $p1, self::SIN60 * $p1];
    }

    // ── Pentágono ──────────────────────────────────────────────────────
    private function pentagon(array $gas): ?array
    {
        $total = array_sum($gas);
        if ($total <= 0) {
            return null;
        }

        // Polígono de los 5 gases sobre sus ejes (gas%·(cos,sin)).
        $cp = [];
        $pct = [];
        foreach ($this->cfg['pentagons']['gas_angles_deg'] as $g => $deg) {
            $p = $gas[$g] / $total * 100;
            $pct[$g] = round($p);
            $rad = deg2rad($deg);
            $cp[] = [$p * cos($rad), $p * sin($rad)];
        }
        $center = $this->centroid($cp);

        $zones = [];
        foreach (['P1', 'P2', 'combine'] as $key) {
            $zones[$key] = $this->locate($center, $this->cfg['pentagons'][$key]['zones'], fn ($p) => $p);
        }

        return ['point' => $center, 'pct' => $pct, 'zones' => $zones];
    }

    /**
     * Centroide de un polígono (fórmula de área con signo, Ec. 1-3 del paper
     * Cheim/Duval/Haider 2020). Para datos reales (≥2 gases) el área es > 0 y esto
     * coincide con el paper al 2º decimal.
     *
     * Caso degenerado (área ≈ 0): pasa SOLO cuando hay un único gas presente — los
     * 5 puntos quedan colineales sobre un eje. El límite del centroide de área es
     * entonces V/3 (el punto del gas dividido 3), NO el promedio V/5. Coincide con
     * los casos extremos publicados (H2=100→(0,33.3), CH4=100→(-19.5,-26.9),
     * C2H6=100→(-31.6,10.3)). Con 0 gases, pentagon() ya devolvió null antes.
     */
    private function centroid(array $pts): array
    {
        $area = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            [$x0, $y0] = $pts[$i];
            [$x1, $y1] = $pts[($i + 1) % $n];
            $cross = $x0 * $y1 - $x1 * $y0;
            $area += $cross;
            $cx += ($x0 + $x1) * $cross;
            $cy += ($y0 + $y1) * $cross;
        }
        $area /= 2;
        if (abs($area) < 1e-9) {
            $nz = array_values(array_filter($pts, fn ($p) => abs($p[0]) > 1e-9 || abs($p[1]) > 1e-9));
            if (count($nz) === 1) {
                return [round($nz[0][0] / 3, 3), round($nz[0][1] / 3, 3)];
            }
            $mx = array_sum(array_column($pts, 0)) / $n;
            $my = array_sum(array_column($pts, 1)) / $n;
            return [round($mx, 3), round($my, 3)];
        }
        return [round($cx / (6 * $area), 3), round($cy / (6 * $area), 3)];
    }

    // ── Geometría común ────────────────────────────────────────────────
    /** Primera zona cuyo polígono (proyectado por $proj) contiene al punto. */
    private function locate(array $point, array $zones, callable $proj): ?string
    {
        foreach ($zones as $z) {
            $poly = array_map($proj, $z['pts']);
            if ($this->inPolygon($point, $poly)) {
                return $z['code'];
            }
        }
        return null;
    }

    /** Ray casting. $poly = lista de [x,y]. */
    private function inPolygon(array $pt, array $poly): bool
    {
        [$x, $y] = $pt;
        $inside = false;
        $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $poly[$i];
            [$xj, $yj] = $poly[$j];
            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        return $inside;
    }
}
