{{-- Detalle COMPLETO del diagnóstico para el portal compartido — mismo nivel de
     información que el informe PDF: narrativa por prueba, tablas de muestras con
     límites normativos (excedidos en rojo) y conclusiones. Recibe $detail
     (ReportShareService::detail) y usa los helpers de estilo del layout share. --}}
@php
    $CK  = ['Muy Bueno' => 'muy_bueno', 'Bueno' => 'bueno', 'Medio' => 'medio', 'Malo' => 'malo', 'Muy Malo' => 'muy_malo'];
    $HEX = ['green' => '#1D7044', 'lime' => '#5AA82E', 'yellow' => '#E9A23B', 'orange' => '#E2661E', 'red' => '#C8281D'];
    $cond = fn ($c) => \App\Support\Diagnostics\ConditionLabel::forCondition($c);
    $condByKey = \App\Support\Diagnostics\ConditionLabel::i18nOverrides();
    $hex = fn ($c) => $HEX[$c] ?? '#9aa0a6';
    $GASES = ['h2'=>'H₂','ch4'=>'CH₄','c2h2'=>'C₂H₂','c2h4'=>'C₂H₄','c2h6'=>'C₂H₆','co'=>'CO','co2'=>'CO₂','o2'=>'O₂','n2'=>'N₂'];

    $cromas  = $detail['cromas'];
    $fiquis  = $detail['fiquis'];
    $furanos = $detail['furanos'];
    $fpots   = $detail['fpots'];
    $gasLimits = $detail['gasLimits'];
    $fiquisLimits = $detail['fiquisLimits'];

    // Narrativas (mismas claves i18n que el informe PDF).
    // Línea de estado/urgencia que encabeza cada conclusión (escala de acción común).
    $urg = fn ($lvl) => __('diagnostics.urgency_' . ((['routine', 'watch', 'investigate', 'critical'][$lvl] ?? 'routine')));
    // Resalte por severidad (investigar naranja, crítico rojo/negrita); rutina/seguimiento normal.
    $urgStyle = fn ($lvl) => match ((int) $lvl) {
        3 => 'color:#C8281D;font-weight:bold',
        2 => 'color:#E2661E;font-weight:bold',
        default => '',
    };
    $urgSpan = fn ($lvl) => '<span style="' . $urgStyle($lvl) . '">' . e($urg($lvl)) . '</span> ';
    $vc = $detail['summary']['cromas'] ?? []; $cDiag = []; $cConcl = null;
    if (($vc['has_data'] ?? false) && ($vc['condition'] ?? null) !== null) {
        $cDiag[] = __('cromas.diag.state', ['score' => $vc['score'] ?? '—', 'condition' => $cond($vc['condition'])]);
        $cDiag[] = ($vc['active_fault'] ?? false) ? __('cromas.diag.fault') : __('cromas.diag.no_fault');
        if (($vc['active_fault'] ?? false) && !empty($vc['fault_zone'])) {
            $cDiag[] = __('cromas.diag.fault_duval', ['zone' => __('cromas.duval.' . $vc['fault_zone'])]);
        }
        if (($vc['active_fault'] ?? false) && !empty($vc['over'])) {
            $cDiag[] = __('cromas.diag.gases_over', ['gases' => implode(', ', array_filter(array_column($vc['over'], 'field')))]);
        }
        if (!empty($vc['approaching'])) {
            $cDiag[] = __('cromas.diag.gases_near', ['gases' => implode(', ', $vc['approaching'])]);
        }
        if ($vc['active_fault'] ?? false) {
            $cConcl = __('cromas.diag.concl_fault');
            if (($vc['rating'] ?? 4) >= 3) { $cConcl .= ' ' . __('cromas.diag.concl_fault_mild', ['condition' => $cond($vc['condition'])]); }
        } else {
            $cConcl = (($vc['rating'] ?? 4) >= 3) ? __('cromas.diag.concl_routine') : __('cromas.diag.concl_watch');
        }
        if (!empty($vc['approaching'])) {
            $cConcl .= ' ' . __('cromas.diag.concl_approaching', ['gases' => implode(', ', $vc['approaching'])]);
        }
    }
    $vf = $detail['summary']['fiquis'] ?? []; $fDiag = []; $fConcl = null;
    if (($vf['has_data'] ?? false) && ($vf['condition'] ?? null) !== null) {
        $fDiag[] = __('fiquis.diag.state', ['condition' => $cond($vf['condition']), 'class' => $vf['class'] ? __('fiquis.diag.class_' . $vf['class']) : __('fiquis.diag.class_na')]);
        if (!empty($vf['approaching'])) {
            $fDiag[] = __('fiquis.diag.params_near', ['list' => implode(', ', $vf['approaching'])]);
        }
        $fLvl = $vf['action_level'] ?? 0;
        $fConcl = __('fiquis.diag.' . ($fLvl >= 2 ? 'concl_investigate' : ($fLvl === 1 ? 'concl_watch' : 'concl_routine')));
        if (!empty($vf['approaching'])) {
            $fConcl .= ' ' . __('fiquis.diag.concl_approaching', ['list' => implode(', ', $vf['approaching'])]);
        }
    }
    $vu = $detail['summary']['furanos'] ?? []; $uDiag = [];
    if (($vu['has_data'] ?? false) && ($vu['dp'] ?? null) !== null) {
        $uDiag[] = __('transformers.report.furanos_dp_line', ['dp' => $vu['dp'], 'life' => $vu['life_percent'] ?? '—']);
    }
    $vp = $detail['summary']['fpot'] ?? []; $pDiag = []; $pConcl = null;
    if (($vp['has_data'] ?? false) && ($vp['condition'] ?? null) !== null) {
        $pDiag[] = __('fpot.diag.value', ['v' => $vp['value'] ?? '—', 'condition' => $cond($vp['condition'])]);
        if (($vp['exceeds'] ?? false) && ($vp['limit'] ?? null) !== null) {
            $pDiag[] = __('fpot.diag.over', ['limit' => $vp['limit']]);
        } elseif (($vp['near'] ?? false) && ($vp['limit'] ?? null) !== null) {
            $pDiag[] = __('fpot.diag.near', ['limit' => $vp['limit']]);
        }
        $pLvl = $vp['action_level'] ?? 0;
        $pConcl = __('fpot.diag.' . ($pLvl >= 2 ? 'concl_investigate' : ($pLvl === 1 ? 'concl_watch' : 'concl_routine')));
        if (($vp['near'] ?? false) && (($vp['rating'] ?? 4) >= 3) && ($vp['limit'] ?? null) !== null) {
            $pConcl .= ' ' . __('fpot.diag.concl_approaching', ['limit' => $vp['limit']]);
        }
    }

    $fOver = function ($key, $val) use ($fiquisLimits) {
        $fl = $fiquisLimits[$key] ?? null;
        if (!$fl || $val === null) return false;
        return $fl['dir'] === 'max' ? (float) $val > $fl['value'] : (float) $val < $fl['value'];
    };
@endphp

{{-- Leyenda del semáforo --}}
<div class="card">
    <span class="muted" style="font-size:12px; text-transform:uppercase; letter-spacing:.04em; margin-right:8px;">{{ __('diagnostics.legend') }}</span>
    @foreach (['muy_bueno' => 'green', 'bueno' => 'lime', 'medio' => 'yellow', 'malo' => 'orange', 'muy_malo' => 'red'] as $k => $col)
        <span class="pill" style="background: {{ $hex($col) }}; margin-right:6px;">{{ $condByKey['cond_' . $k] ?? __('diagnostics.cond_' . $k) }}</span>
    @endforeach
</div>

{{-- ── Gases disueltos ── --}}
@if (count($cromas))
<div class="card">
    <h2 class="h2">{{ __('transformers.report.dga') }}</h2>
    @if ($cDiag)<ul class="narr">@foreach ($cDiag as $l)<li>{{ $l }}</li>@endforeach</ul>@endif
    <div class="tscroll">
        <table class="list data">
            <thead><tr><th>{{ __('cromas.sample_date') }}</th>@foreach ($GASES as $g => $lbl)<th>{{ $lbl }}@if (($gasLimits[$g] ?? null) !== null)<small>≤ {{ $gasLimits[$g] }}</small>@endif</th>@endforeach<th>{{ __('cromas.state') }}</th></tr></thead>
            <tbody>
            @foreach ($cromas as $r)
                <tr><td>{{ \Illuminate\Support\Str::before($r['sample_date'] ?? '', ' ') }}</td>
                @foreach ($GASES as $g => $lbl)<td @class(['over' => ($gasLimits[$g] ?? null) !== null && $r[$g] !== null && (float) $r[$g] > (float) $gasLimits[$g]])>{{ $r[$g] ?? '—' }}</td>@endforeach
                <td>@if ($r['condition'])<span class="pill" style="background: {{ $hex($r['color']) }}">{{ $cond($r['condition']) }}</span>@else <span class="muted">—</span> @endif</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── Calidad de aceite ── --}}
@if (count($fiquis))
<div class="card">
    <h2 class="h2">{{ __('transformers.report.oil_quality') }}</h2>
    @if ($fDiag)<ul class="narr">@foreach ($fDiag as $l)<li>{{ $l }}</li>@endforeach</ul>@endif
    <div class="tscroll">
        <table class="list data">
            <thead><tr><th>{{ __('fiquis.sample_date') }}</th>@foreach ($detail['fiquisColumns'] as $c)<th>{{ __('fiquis.' . $c['key']) }}<small>{{ ($c['astm'] ? $c['astm'] . ' ' : '') . $c['unit'] }}@if (isset($fiquisLimits[$c['key']])) · {{ $fiquisLimits[$c['key']]['dir'] === 'max' ? '≤' : '≥' }} {{ $fiquisLimits[$c['key']]['value'] }}@endif</small></th>@endforeach<th>{{ __('fiquis.state') }}</th></tr></thead>
            <tbody>
            @foreach ($fiquis as $r)
                <tr><td>{{ \Illuminate\Support\Str::before($r['sample_date'] ?? '', ' ') }}</td>
                @foreach ($detail['fiquisColumns'] as $c)<td @class(['over' => $fOver($c['key'], $r[$c['key']] ?? null)])>{{ $r[$c['key']] ?? '—' }}</td>@endforeach
                <td>@if ($r['condition'])<span class="pill" style="background: {{ $hex($r['color']) }}">{{ $cond($r['condition']) }}</span>@else <span class="muted">—</span> @endif</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── Furanos ── --}}
@if (count($furanos))
<div class="card">
    <h2 class="h2">{{ __('furanos.tab') }}</h2>
    @if ($uDiag)<ul class="narr">@foreach ($uDiag as $l)<li>{{ $l }}</li>@endforeach</ul>@endif
    <table class="list data">
        <thead><tr><th>{{ __('furanos.sample_date') }}</th><th>2FAL (ppb)</th><th>{{ __('furanos.dp') }}</th><th>{{ __('furanos.state') }}</th></tr></thead>
        <tbody>
        @foreach ($furanos as $r)
            <tr><td>{{ \Illuminate\Support\Str::before($r['sample_date'] ?? '', ' ') }}</td><td>{{ $r['fal'] ?? '—' }}</td><td>{{ $r['dp'] ?? '—' }}</td>
            <td>@if ($r['condition'])<span class="pill" style="background: {{ $hex($r['color']) }}">{{ $cond($r['condition']) }}</span>@else <span class="muted">—</span> @endif</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- ── Factor de potencia ── --}}
@if (count($fpots))
<div class="card">
    <h2 class="h2">{{ __('fpot.tab') }}</h2>
    @if ($pDiag)<ul class="narr">@foreach ($pDiag as $l)<li>{{ $l }}</li>@endforeach</ul>@endif
    <table class="list data">
        <thead><tr><th>{{ __('fpot.sample_date') }}</th><th>{{ __('fpot.value') }}</th><th>{{ __('fpot.temperature') }}</th><th>{{ __('fpot.state') }}</th></tr></thead>
        <tbody>
        @foreach ($fpots as $r)
            <tr><td>{{ \Illuminate\Support\Str::before($r['sample_date'] ?? '', ' ') }}</td><td>{{ $r['value'] ?? '—' }}</td><td>{{ $r['temperature'] ?? '—' }}</td>
            <td>@if ($r['condition'])<span class="pill" style="background: {{ $hex($r['color']) }}">{{ $cond($r['condition']) }}</span>@else <span class="muted">—</span> @endif</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- ── Conclusiones ── --}}
@if ($cConcl || $fConcl || count($uDiag) || count($pDiag))
<div class="card reco">
    <h2 class="h2">{{ __('transformers.report.conclusions') }}</h2>
    @if ($cConcl)<p><b>{{ __('cromas.tab') }}:</b> {!! $urgSpan($vc['action_level'] ?? 0) !!}{{ $cConcl }}</p>@endif
    @if ($fConcl)<p><b>{{ __('fiquis.tab') }}:</b> {!! $urgSpan($vf['action_level'] ?? 0) !!}{{ $fConcl }}</p>@endif
    @if (count($furanos) && ($vu['condition'] ?? null) !== null)
        <p><b>{{ __('furanos.tab') }}:</b> {!! $urgSpan($vu['action_level'] ?? 0) !!}{{ __('furanos.' . ['reco_routine', 'reco_monitor', 'reco_increase', 'reco_critical'][$vu['action_level'] ?? 0]) }}</p>
    @endif
    @if (count($fpots) && ($vp['condition'] ?? null) !== null)
        <p><b>{{ __('fpot.tab') }}:</b> {!! $urgSpan($vp['action_level'] ?? 0) !!}{{ $pConcl }}</p>
    @endif
</div>
@endif
