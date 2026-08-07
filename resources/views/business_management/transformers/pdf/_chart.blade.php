{{-- Gráfico de línea simple en SVG para DomPDF (no hay JS/ECharts en el PDF).
     Espera: $points = [['x'=>label, 'y'=>float], ...] cronológico, $color, $title. --}}
@php
    $n = count($points);
    $w = 512; $h = 168; $padL = 46; $padR = 14; $padT = 10; $padB = 26;
    $plotW = $w - $padL - $padR; $plotH = $h - $padT - $padB;
    $ys = array_map(fn ($p) => $p['y'], $points);
    $min = $n ? min($ys) : 0;
    $max = $n ? max($ys) : 1;
    if ($max <= $min) { $max = $min + max(1, abs($min) * 0.1); }
    $sx = fn ($i) => $n <= 1 ? $padL + $plotW / 2 : $padL + $plotW * $i / ($n - 1);
    $sy = fn ($v) => $padT + $plotH * (1 - ($v - $min) / ($max - $min));
    $poly = [];
    foreach ($points as $i => $p) { $poly[] = round($sx($i), 1) . ',' . round($sy($p['y']), 1); }
    $poly = implode(' ', $poly);
@endphp
<div class="chart">
    <div class="chart__title">{{ $title }}</div>
    <svg width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}">
        <line x1="{{ $padL }}" y1="{{ $padT }}" x2="{{ $padL }}" y2="{{ $padT + $plotH }}" stroke="#cccccc" stroke-width="1"/>
        <line x1="{{ $padL }}" y1="{{ $padT + $plotH }}" x2="{{ $padL + $plotW }}" y2="{{ $padT + $plotH }}" stroke="#cccccc" stroke-width="1"/>
        <text x="{{ $padL - 5 }}" y="{{ $padT + 4 }}" text-anchor="end" font-size="7" fill="#6A6D70">{{ round($max, 1) }}</text>
        <text x="{{ $padL - 5 }}" y="{{ $padT + $plotH }}" text-anchor="end" font-size="7" fill="#6A6D70">{{ round($min, 1) }}</text>
        @if ($n >= 1)
            <polyline points="{{ $poly }}" fill="none" stroke="{{ $color }}" stroke-width="2"/>
            @foreach ($points as $i => $p)
                <circle cx="{{ round($sx($i), 1) }}" cy="{{ round($sy($p['y']), 1) }}" r="2.4" fill="{{ $color }}"/>
            @endforeach
            <text x="{{ $padL }}" y="{{ $padT + $plotH + 14 }}" text-anchor="start" font-size="6.5" fill="#6A6D70">{{ $points[0]['x'] }}</text>
            @if ($n > 1)
                <text x="{{ $padL + $plotW }}" y="{{ $padT + $plotH + 14 }}" text-anchor="end" font-size="6.5" fill="#6A6D70">{{ $points[$n - 1]['x'] }}</text>
            @endif
        @endif
    </svg>
</div>
