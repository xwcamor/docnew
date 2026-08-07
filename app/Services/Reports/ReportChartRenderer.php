<?php

namespace App\Services\Reports;

use App\Models\Transformer;
use App\Services\Diagnostics\ChromatographyEngine;
use App\Services\Diagnostics\DuvalService;

/**
 * ReportChartRenderer — dibuja los gráficos del informe en el SERVIDOR con GD
 * (PNG data-URIs), sin navegador.
 *
 * En la app, los gráficos los captura echarts en el navegador del usuario
 * logueado (Show.vue) y se mandan al PDF. Pero el portal compartido (cliente
 * externo) no tiene navegador → este renderer reproduce server-side las mismas
 * tendencias por gas/parámetro y los triángulos de Duval, para que el PDF
 * descargado del portal sea idéntico al de la app. Determinístico, sin deps.
 *
 * Formato de salida: [{group, label, dataURL}], igual que lo que envía el front
 * — el blade del informe los consume sin distinguir el origen.
 */
class ReportChartRenderer
{
    // Colores por gas/parámetro (mismos que CROMAS_SERIES/FIQUIS_SERIES del front).
    private const GAS = [
        'h2' => ['H2', '#C8281D'], 'o2' => ['O2', '#D81B60'], 'n2' => ['N2', '#5D4037'],
        'ch4' => ['CH4', '#E9A23B'], 'co' => ['CO', '#7F8C8D'], 'co2' => ['CO2', '#16A34A'],
        'c2h4' => ['C2H4', '#0A6ED1'], 'c2h6' => ['C2H6', '#2AA198'], 'c2h2' => ['C2H2', '#8E44AD'],
    ];
    private const FIQ = [
        'rig' => '#0A6ED1', 'ten' => '#2AA198', 'acid' => '#C8281D', 'wat' => '#E9A23B', 'pot' => '#8E44AD',
    ];
    // Color por zona de Duval (mismo mapa que DuvalTab.vue).
    private const ZONE = [
        'PD' => '#7E57C2', 'T1' => '#64B5F6', 'T2' => '#9FA8DA', 'T3' => '#D1D9F5',
        'D1' => '#B3E5FC', 'D2' => '#2962FF', 'DT' => '#0288D1',
        'S' => '#4DD0E1', 'C' => '#26A69A', 'O' => '#26C281', 'ND' => '#80CBC4',
        'T1-O' => '#A5D6B7', 'T1-C' => '#ECEFF1', 'T2-O' => '#FFF8E1', 'T2-C' => '#90CAF9',
        'T3-C' => '#E1BEE7', 'T3-H' => '#CE93D8',
    ];
    // Gases en los vértices de cada triángulo [apex, abajo-der, abajo-izq].
    private const TRI_VERTS = [
        'T1' => ['CH4', 'C2H4', 'C2H2'], 'T3' => ['CH4', 'C2H4', 'C2H2'],
        'T4' => ['H2', 'CH4', 'C2H6'],   'T5' => ['CH4', 'C2H4', 'C2H6'],
    ];

    public function __construct(private DuvalService $duval, private ChromatographyEngine $cromas)
    {
    }

    /** Disponible solo si la extensión GD con soporte PNG está cargada. */
    public function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    /**
     * Arma todos los gráficos del informe: tendencia por gas, por parámetro
     * fisicoquímico y triángulos de Duval de la última muestra.
     *
     * @return array<int,array{group:string,label:string,dataURL:string}>
     */
    public function charts(Transformer $transformer, array $cromas, array $fiquis, array $fiquisColumns, array $cromasBands, array $fiquisBands, array $furanos = []): array
    {
        if (!$this->available()) {
            return [];
        }

        $out = [];

        // ── Tendencias de gases: un gráfico por gas con al menos un valor ──
        foreach (self::GAS as $key => [$label, $color]) {
            $series = $this->series($cromas, $key);
            if ($series['hasData']) {
                $png = $this->lineChart($series['dates'], $series['values'], $cromasBands[$key] ?? [], $color);
                $out[] = ['group' => 'cromas', 'label' => $label, 'dataURL' => $png];
            }
        }

        // ── Tendencias fisicoquímicas: un gráfico por columna scored ──
        foreach ($fiquisColumns as $c) {
            $key = $c['key'];
            if (($c['mode'] ?? 'scored') !== 'scored') {
                continue; // las 'limit' (rig877/pot100) no grafican
            }
            $series = $this->series($fiquis, $key);
            if ($series['hasData']) {
                $png = $this->lineChart($series['dates'], $series['values'], $fiquisBands[$key] ?? [], self::FIQ[$key] ?? '#0A6ED1');
                $out[] = ['group' => 'fiquis', 'label' => __('fiquis.' . $key), 'dataURL' => $png];
            }
        }

        // ── Tendencia del DP (furanos): vida del papel — cae = envejece ──
        $dp = $this->series($furanos, 'dp');
        if ($dp['hasData']) {
            $png = $this->lineChart($dp['dates'], $dp['values'], [], '#32363a');
            $out[] = ['group' => 'furanos', 'label' => 'DP', 'dataURL' => $png];
        }

        // ── Triángulo de Duval: SOLO con falla activa y SOLO el principal
        //    (mismo criterio que el informe logueado: sin falla, no aplica). ──
        $sample = $transformer->latestChromatographical();
        if ($sample && $this->hasActiveFault($sample)) {
            $oil = $transformer->oilType?->code;
            $geom = $this->duval->geometry($oil);
            $eval = $this->duval->evaluate($sample);
            // El principal: T1 (mineral/silicona) o T3 (aceites especiales).
            $key = isset($eval['triangles']['T1']) ? 'T1' : array_key_first($eval['triangles'] ?? []);
            $tri = $eval['triangles'][$key] ?? null;
            $zones = $geom['triangles'][$key] ?? null;
            if ($tri && $zones) {
                $verts = self::TRI_VERTS[$key] ?? ['', '', ''];
                $png = $this->triangle($zones, $tri['point'] ?? null, $verts, $tri['zone'] ?? null);
                $out[] = ['group' => 'duval', 'label' => __('cromas.duval_tab') . ($tri['zone'] ?? null ? ' · ' . $tri['zone'] : ''), 'dataURL' => $png, 'zone' => $tri['zone'] ?? null];
            }
        }

        return $out;
    }

    /** ¿Hay falla activa? (algún gas de falla por encima de su típico, score > 1). */
    private function hasActiveFault(\App\Models\Chromatographical $sample): bool
    {
        foreach ($this->cromas->evaluate($sample)->detail as $d) {
            if (($d['score'] ?? 0) > 1) {
                return true;
            }
        }
        return false;
    }

    /** Extrae serie temporal de una clave (ordenada por fecha asc). */
    private function series(array $samples, string $key): array
    {
        $rows = collect($samples)
            ->map(fn ($s) => ['d' => $s['sample_date'] ?? '', 'v' => $s[$key] ?? null])
            ->sortBy('d')->values();
        $dates = $rows->pluck('d')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $values = $rows->pluck('v')->map(fn ($v) => $v === null ? null : (float) $v)->all();
        $hasData = $rows->contains(fn ($r) => $r['v'] !== null);
        return compact('dates', 'values', 'hasData');
    }

    /**
     * Gráfico de líneas (PNG data-URI) con las MISMAS bandas de severidad
     * coloreadas (verde→ámbar→rojo) que echarts: cada banda de límite pinta su
     * franja según su severidad, para que el portal se vea igual sin navegador.
     *
     * @param array $bands  [{from, to, sev}] (sev 0=mejor..1=peor). Puede ser vacío.
     */
    private function lineChart(array $dates, array $values, array $bands, string $hexColor): string
    {
        $W = 520; $H = 300; $padL = 52; $padR = 16; $padT = 16; $padB = 56;  // 2x: el PDF lo escala
        $im = imagecreatetruecolor($W, $H);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $white);

        $axis  = imagecolorallocate($im, 150, 157, 164);
        $grid  = imagecolorallocate($im, 232, 236, 239);
        $txt   = imagecolorallocate($im, 90, 96, 102);
        $rgb   = sscanf(ltrim($hexColor, '#'), '%02x%02x%02x');
        $line  = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);

        $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
        $x0 = $padL; $y0 = $padT; $x1 = $W - $padR; $y1 = $H - $padB;

        $nums = array_values(array_filter($values, fn ($v) => $v !== null));
        $dataMax = $nums ? max($nums) : 1;
        // Tope del eje: encima del dato y del primer borde de banda por encima.
        $edges = collect($bands)->pluck('to')->filter(fn ($v) => $v !== null)->sort()->values();
        $above = $edges->first(fn ($e) => $e > $dataMax);
        $top = max($above ? $above : $dataMax * 1.2, $dataMax * 1.05, 1);
        $yFor = fn ($v) => $y1 - ($v / $top) * $plotH;
        $n = count($values);
        $xFor = fn ($i) => $n <= 1 ? ($x0 + $plotW / 2) : ($x0 + $i / ($n - 1) * $plotW);

        // ── Bandas de severidad coloreadas (como las markArea de echarts) ──
        foreach ($bands as $b) {
            $from = (float) ($b['from'] ?? 0);
            $to   = $b['to'] === null ? $top : (float) $b['to'];
            if ($from >= $top) {
                continue;
            }
            $to = min($to, $top);
            $col = $this->sevBg($im, (float) ($b['sev'] ?? 0));
            imagefilledrectangle($im, $x0, (int) $yFor($to), $x1, (int) $yFor($from), $col);
        }

        // Grilla horizontal + etiquetas Y (5 líneas).
        $font = $this->ttf();
        for ($g = 0; $g <= 4; $g++) {
            $yv = $top * $g / 4; $y = $yFor($yv);
            imageline($im, $x0, (int) $y, $x1, (int) $y, $grid);
            $lbl = $this->fmt($yv);
            $this->text($im, $font, 9, $x0 - 6 - $this->tw($font, 9, $lbl), $y + 3, $txt, $lbl);
        }

        // Ejes.
        imageline($im, $x0, $y0, $x0, $y1, $axis);
        imageline($im, $x0, $y1, $x1, $y1, $axis);

        // Serie (línea + puntos), saltando nulls.
        $prev = null;
        for ($i = 0; $i < $n; $i++) {
            if ($values[$i] === null) { $prev = null; continue; }
            $x = (int) $xFor($i); $y = (int) $yFor($values[$i]);
            if ($prev !== null) {
                $this->thickLine($im, $prev[0], $prev[1], $x, $y, $line, 2);
            }
            imagefilledellipse($im, $x, $y, 7, 7, $line);
            $prev = [$x, $y];
        }

        // Etiquetas X (fechas) — rotadas para que no se pisen.
        $step = max(1, (int) ceil($n / 6));
        for ($i = 0; $i < $n; $i += $step) {
            $d = substr($dates[$i] ?? '', 2); // sin el siglo (yy-mm-dd)
            $x = (int) $xFor($i);
            $this->textRot($im, $font, 8, $x - 4, $y1 + 8, $txt, $d, 35);
        }

        return $this->toDataUri($im);
    }

    /**
     * Triángulo de Duval (PNG data-URI): zonas coloreadas + punto de la muestra +
     * etiquetas de los gases en cada vértice. Se dibuja a 3x y se reduce con
     * remuestreo → bordes suaves (GD no antialiasa polígonos rellenos).
     *
     * @param array $verts  [apex, abajo-derecha, abajo-izquierda] (gases)
     */
    private function triangle(array $zones, ?array $point, array $verts = ['', '', ''], ?string $zone = null): string
    {
        $k = 3;                       // factor de supersampling
        $S = 380 * $k; $pad = 52 * $k; $side = $S - 2 * $pad;
        $im = imagecreatetruecolor($S, $S);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $white);
        $edge = imagecolorallocate($im, 70, 78, 88);
        $black = imagecolorallocate($im, 24, 28, 34);
        $lblc = imagecolorallocate($im, 90, 96, 104);
        $font = $this->ttf();

        // Vértices del triángulo equilátero (apex arriba, base abajo).
        $px = fn ($x) => $pad + $x * $side;
        $py = fn ($y) => $pad + (0.8660254 - $y) / 0.8660254 * $side;
        $apex = [(int) $px(0.5), (int) $py(0.8660254)];
        $bl   = [(int) $px(0), (int) $py(0)];
        $br   = [(int) $px(1), (int) $py(0)];

        // Zonas (polígonos rellenos semitransparentes).
        foreach ($zones as $z) {
            $pts = $z['pts'] ?? [];
            if (count($pts) < 3) {
                continue;
            }
            $rgb = sscanf(ltrim(self::ZONE[$z['code']] ?? '#B0BEC5', '#'), '%02x%02x%02x');
            $col = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], 60);
            $flat = [];
            foreach ($pts as $p) { $flat[] = (int) $px($p[0]); $flat[] = (int) $py($p[1]); }
            imagefilledpolygon($im, $flat, $col);
        }

        // Borde grueso del triángulo.
        imagesetthickness($im, 2 * $k);
        imagepolygon($im, [$apex[0], $apex[1], $bl[0], $bl[1], $br[0], $br[1]], $edge);
        imagesetthickness($im, 1);

        // Etiquetas de gases en los vértices.
        if ($font) {
            $fs = 13 * $k;
            $this->labelAt($im, $font, $fs, $apex[0], $apex[1] - 8 * $k, $lblc, $verts[0] ?? '', 'center', 'bottom');
            $this->labelAt($im, $font, $fs, $br[0] + 6 * $k, $br[1] + 6 * $k, $lblc, $verts[1] ?? '', 'left', 'top');
            $this->labelAt($im, $font, $fs, $bl[0] - 6 * $k, $bl[1] + 6 * $k, $lblc, $verts[2] ?? '', 'right', 'top');
        }

        // Punto de la muestra (anillo) + zona.
        if ($point) {
            $x = (int) $px($point[0]); $y = (int) $py($point[1]);
            imagefilledellipse($im, $x, $y, 16 * $k, 16 * $k, $black);
            imagefilledellipse($im, $x, $y, 8 * $k, 8 * $k, $white);
            if ($font && $zone) {
                $this->labelAt($im, $font, 12 * $k, $x + 12 * $k, $y, $black, $zone, 'left', 'middle');
            }
        }

        // Reducir con remuestreo → bordes suaves.
        $W = 380; $out = imagecreatetruecolor($W, $W);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $W, $W, $S, $S);
        imagedestroy($im);
        return $this->toDataUri($out);
    }

    /** Texto con anclaje horizontal (left/center/right) y vertical (top/middle/bottom). */
    private function labelAt($im, string $font, float $size, float $x, float $y, int $color, string $s, string $ha, string $va): void
    {
        if ($s === '') {
            return;
        }
        $bb = imagettfbbox($size, 0, $font, $s);
        $w = $bb[2] - $bb[0]; $h = $bb[1] - $bb[7];
        $dx = $ha === 'center' ? -$w / 2 : ($ha === 'right' ? -$w : 0);
        $dy = $va === 'middle' ? $h / 2 : ($va === 'bottom' ? 0 : $h);
        imagettftext($im, $size, 0, (int) ($x + $dx), (int) ($y + $dy), $color, $font, $s);
    }

    // ── Helpers GD ──────────────────────────────────────────────────────────

    private function ttf(): ?string
    {
        $f = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        return is_file($f) ? $f : null;
    }

    private function text($im, ?string $font, int $size, float $x, float $y, int $color, string $s): void
    {
        if ($font) {
            imagettftext($im, $size, 0, (int) $x, (int) $y, $color, $font, $s);
        } else {
            imagestring($im, 2, (int) $x, (int) $y - 10, $s, $color);
        }
    }

    private function textRot($im, ?string $font, int $size, float $x, float $y, int $color, string $s, float $angle): void
    {
        if ($font) {
            imagettftext($im, $size, $angle, (int) $x, (int) $y + 10, $color, $font, $s);
        } else {
            imagestring($im, 1, (int) $x, (int) $y, $s, $color);
        }
    }

    private function tw(?string $font, int $size, string $s): float
    {
        if ($font) {
            $bb = imagettfbbox($size, 0, $font, $s);
            return $bb[2] - $bb[0];
        }
        return strlen($s) * 6;
    }

    /** Línea con grosor (GD no tiene width nativo confiable con estilos). */
    private function thickLine($im, int $x1, int $y1, int $x2, int $y2, int $color, int $w): void
    {
        for ($o = -intdiv($w, 2); $o <= intdiv($w, 2); $o++) {
            imageline($im, $x1, $y1 + $o, $x2, $y2 + $o, $color);
            imageline($im, $x1 + $o, $y1, $x2 + $o, $y2, $color);
        }
    }

    /** Color de fondo (semitransparente) para una severidad 0..1: verde→ámbar→rojo. */
    private function sevBg($im, float $sev): int
    {
        $sev = max(0.0, min(1.0, $sev));
        $stops = [[46, 147, 60], [233, 162, 59], [200, 40, 29]]; // verde, ámbar, rojo
        [$a, $b, $f] = $sev < 0.5 ? [$stops[0], $stops[1], $sev * 2] : [$stops[1], $stops[2], ($sev - 0.5) * 2];
        $r = (int) round($a[0] + ($b[0] - $a[0]) * $f);
        $g = (int) round($a[1] + ($b[1] - $a[1]) * $f);
        $bl = (int) round($a[2] + ($b[2] - $a[2]) * $f);
        return imagecolorallocatealpha($im, $r, $g, $bl, 108); // ~0.15 opacidad
    }

    private function fmt(float $v): string
    {
        if ($v >= 1000) return round($v / 1000, 1) . 'k';
        if ($v >= 10)   return (string) round($v);
        if ($v >= 1)    return (string) round($v, 1);
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }

    private function toDataUri($im): string
    {
        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);
        return 'data:image/png;base64,' . base64_encode($png);
    }
}
