<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('transformers.report.title') }} {{ $transformer->serial ?: $transformer->tag }}</title>
    <style>
        @page { margin: 26px 32px 76px 32px; }
        body { font-family: Helvetica; font-size: 9.5pt; color: #2b3138; margin: 0; line-height: 1.4; }
        /* Encabezado corporativo */
        .hdr { width: 100%; margin-bottom: 4px; }
        .hdr td { vertical-align: middle; }
        .hdr__logo { height: 44px; max-width: 230px; }
        .hdr__company { font-size: 11pt; font-weight: bold; color: #354A5F; }
        /* Carátula de página completa */
        .cover { page-break-after: always; text-align: center; }
        .cover__brand { margin-top: 70px; }
        .cover__brand img { max-height: 92px; max-width: 320px; }
        .cover__company { font-size: 19pt; font-weight: bold; color: #354A5F; letter-spacing: 0.03em; }
        .cover__title { font-size: 23pt; font-weight: bold; color: #1f2937; text-transform: uppercase; letter-spacing: 0.07em; margin-top: 74px; }
        .cover__rule { width: 120px; height: 0; border: none; border-top: 3px solid #C8281D; margin: 16px auto 0; }
        .cover__subject { font-size: 13pt; font-weight: bold; color: #354A5F; margin-top: 28px; }
        .cover__sub { font-size: 9.5pt; color: #6A6D70; margin-top: 5px; letter-spacing: 0.02em; }
        .cover__meta { width: 66%; margin: 72px auto 0; border-collapse: collapse; }
        .cover__meta td { border: 1px solid #D7DCE1; padding: 7px 13px; font-size: 9pt; text-align: left; }
        .cover__meta td.k { background: #F2F5F8; font-weight: bold; color: #354A5F; width: 42%; text-transform: uppercase; letter-spacing: 0.03em; font-size: 8pt; }
        .cover__client { margin-top: 20px; }
        .cover__client img { max-height: 50px; max-width: 190px; }
        /* Panel ejecutivo de portada (dashboard: gauge HI + veredicto por prueba) */
        .cover__dash { width: 78%; margin: 34px auto 0; border-collapse: collapse; }
        .cover__dash > tr > td, .cover__dash td.gcell, .cover__dash td.vcell { vertical-align: middle; }
        .gcell { width: 150px; text-align: center; }
        .higauge { width: 116px; height: 116px; }
        .hicircle { width: 116px; height: 116px; border-radius: 58px; margin: 0 auto; }
        .hicircle__n { font-size: 27pt; font-weight: bold; color: #fff; padding-top: 30px; line-height: 1; }
        .hicircle__c { font-size: 7.5pt; font-weight: bold; color: #fff; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 3px; }
        .hicircle__cap { font-size: 6.8pt; color: #8A9099; text-transform: uppercase; letter-spacing: 0.07em; margin-top: 6px; }
        .vcell { padding-left: 22px; }
        table.vlist { width: 100%; border-collapse: collapse; }
        table.vlist td { padding: 5px 0; border-bottom: 1px solid #ECEFF2; font-size: 9pt; text-align: left; }
        table.vlist tr:last-child td { border-bottom: none; }
        table.vlist .vn { font-weight: bold; color: #2b3138; }
        table.vlist .vm { color: #8A9099; font-size: 7.8pt; text-align: right; }
        .pill--lg { font-size: 8.5pt; padding: 2px 12px; border-radius: 9px; }
        .cover__verify { margin-top: 22px; font-size: 7pt; color: #9aa0a6; letter-spacing: 0.04em; }
        .cover__verify b { color: #6A6D70; font-family: monospace; letter-spacing: 0.08em; }
        .cover__qr { width: 64px; height: 64px; margin-bottom: 3px; }
        /* Banda de título (páginas de contenido) */
        .tband { width: 100%; border-collapse: collapse; margin: 6px 0 4px; }
        .tband td { background: #354A5F; color: #fff; padding: 12px 16px; text-align: center; }
        .tband .t { font-size: 15pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; }
        .tband .s { font-size: 8.5pt; color: #cdd6df; margin-top: 2px; letter-spacing: 0.03em; }
        /* Metadatos del informe (código, fecha, responsable) */
        .meta { width: 100%; border-collapse: collapse; margin: 0 0 14px; }
        .meta td { border: 1px solid #D7DCE1; padding: 5px 9px; font-size: 8.2pt; }
        .meta td.k { background: #F2F5F8; font-weight: bold; width: 15%; color: #354A5F; text-transform: uppercase; letter-spacing: 0.03em; font-size: 7.4pt; }
        /* Bloque de firmas */
        .sign { width: 100%; border-collapse: collapse; margin: 22px 0 4px; page-break-inside: avoid; }
        .sign td { width: 50%; padding: 0 18px; vertical-align: bottom; }
        .sign__line { border-top: 1px solid #2b3138; margin-top: 46px; padding-top: 4px; text-align: center; }
        .sign__img { text-align: center; margin-top: 4px; }
        .sign__img img { max-height: 42px; max-width: 170px; }
        .sign__role { font-size: 8.5pt; font-weight: bold; color: #2b3138; }
        .sign__name { font-size: 7.8pt; color: #6A6D70; }
        .sign__title { font-size: 7.2pt; color: #8A8D90; }
        .sign__stamp { font-size: 7pt; color: #9aa0a6; text-align: center; margin-top: 4px; font-style: italic; }
        /* Estado EN REVISIÓN (borrador del flujo de aprobación) */
        .review-banner { background: #E9A23B; color: #fff; text-align: center; font-weight: bold; font-size: 11pt; letter-spacing: 0.14em; text-transform: uppercase; padding: 7px 6px; margin: 0 0 8px; }
        .review-note { color: #B5781A; font-size: 7.6pt; text-align: center; margin: 0 0 8px; }
        /* Resumen ejecutivo */
        .exec { width: 100%; border-collapse: collapse; margin: 0 0 12px; }
        .exec td { border: 1px solid #E5E5E5; vertical-align: middle; }
        .exec .hi { width: 26%; text-align: center; background: #F7F9FB; padding: 10px 8px; }
        .exec .hi .n { font-size: 22pt; font-weight: bold; color: #1f2937; }
        .exec .hi .cap { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #6A6D70; margin-top: 2px; }
        .exec table.tests { width: 100%; border-collapse: collapse; }
        .exec table.tests td { border: none; border-bottom: 1px solid #EEF0F2; padding: 5px 10px; font-size: 8pt; }
        .exec table.tests tr:last-child td { border-bottom: none; }
        .exec .tn { font-weight: bold; color: #1f2937; width: 34%; }
        .exec .tm { color: #6A6D70; }
        /* Secciones numeradas */
        .ssec { background: #EEF2F6; border-left: 4px solid #354A5F; padding: 7px 11px; font-size: 9.2pt; font-weight: bold; color: #354A5F; text-transform: uppercase; letter-spacing: 0.05em; margin: 16px 0 8px; }
        table.kv { width: 100%; border-collapse: collapse; margin: 0 0 6px; }
        table.kv td { border: 1px solid #E5E5E5; padding: 5px 9px; font-size: 8.5pt; }
        table.kv td.k { background: #F7F9FB; font-weight: bold; width: 16%; color: #1f2937; }
        .pill { display: inline-block; padding: 1px 9px; border-radius: 8px; color: #fff; font-weight: bold; font-size: 7.5pt; }
        .narr { margin: 0 0 8px; padding-left: 16px; }
        .narr li { font-size: 8.7pt; line-height: 1.55; color: #334155; margin-bottom: 3px; }
        .reco { background: #FFFBEB; border-left: 3px solid #E9A23B; padding: 7px 11px; margin: 0 0 8px; }
        .reco p { font-size: 8pt; line-height: 1.55; color: #334155; margin: 0 0 3px; }
        table.data { width: 100%; border-collapse: collapse; margin: 0 0 6px; }
        table.data thead th { background: #354A5F; color: #fff; font-weight: bold; font-size: 6.8pt; text-align: center; padding: 4px 3px; border: 1px solid #2B3A49; }
        table.data thead th small { display: block; font-weight: normal; font-size: 6pt; color: #cbd5e1; }
        /* 2ª línea de la cabecera: símbolo químico (cromas) o método + condición
           de ensayo (fiquis: "ASTM D1816 · 2 mm"). nowrap para que no se parta
           en "…· 2 / mm" y descuadre la altura de las celdas vecinas. */
        table.data thead th .sym { display: block; font-weight: bold; font-size: 6.4pt; color: #fff; white-space: nowrap; }
        table.data thead th .sym.method { font-weight: normal; font-size: 5.6pt; color: #dbe3ea; }
        table.data thead th .lim { display: block; font-weight: bold; font-size: 5.8pt; color: #FBD9A6; letter-spacing: 0.02em; }
        /* El límite dentro de una CELDA (tabla de tasas IEEE): solo tenía estilo
           el de la cabecera, así que salía pegado al valor ("1426.14Máx 10"). */
        table.data td .lim { padding-left: 6px; font-size: 6.2pt; color: #8A9099; white-space: nowrap; }
        table.data tbody td { padding: 3px; border: 1px solid #E5E5E5; font-size: 6.8pt; text-align: center; }
        table.data tbody td.over { background: #FCEBEA !important; color: #C8281D; font-weight: bold; }
        /* dompdf parte filas en el corte de página: que rompa SOLO entre filas. */
        table.data tbody tr { page-break-inside: avoid; }
        table.data thead { display: table-header-group; }  /* repite encabezado al partir */
        table.data tbody tr:nth-child(even) td { background: #FAFBFC; }
        .charts { width: 100%; } .charts td { width: 50%; padding: 2px 2px 8px; vertical-align: top; } .charts img { width: 100%; }
        .chart-cap { font-size: 6.8pt; font-weight: bold; color: #354A5F; text-align: center; padding: 3px 0 2px; text-transform: uppercase; letter-spacing: 0.03em; }
        .foot { margin-top: 12px; padding-top: 6px; border-top: 1px solid #C8281D; font-size: 6.5pt; color: #6A6D70; text-align: center; }
        /* Leyenda del semáforo */
        .legend { margin: 0 0 14px; }
        .legend__cap { font-weight: bold; color: #354A5F; text-transform: uppercase; letter-spacing: 0.04em; font-size: 7.4pt; margin-right: 7px; }
        .legend .pill { margin-right: 5px; }
    </style>
</head>
<body>
@php
    $CK = ['Muy Bueno' => 'muy_bueno', 'Bueno' => 'bueno', 'Medio' => 'medio', 'Malo' => 'malo', 'Muy Malo' => 'muy_malo'];
    $HEX = ['green' => '#1D7044', 'lime' => '#5AA82E', 'yellow' => '#E9A23B', 'orange' => '#E2661E', 'red' => '#C8281D'];
    $cond = fn ($c) => \App\Support\Diagnostics\ConditionLabel::forCondition($c);
    $condByKey = \App\Support\Diagnostics\ConditionLabel::i18nOverrides(); // ['cond_muy_bueno' => etiqueta editada, ...]
    $hex = fn ($c) => $HEX[$c] ?? '#9aa0a6';
    // Cabecera de fiquis: norma (línea 2) y medida con su condición (línea 3).
    // Salen de los archivos de idioma, igual que el nombre; la columna del
    // backend queda de respaldo para parámetros sin traducción.
    $fqAstm = fn ($c) => \Illuminate\Support\Facades\Lang::has('fiquis.' . $c['key'] . '_astm')
        ? __('fiquis.' . $c['key'] . '_astm')
        : ($c['astm'] ? 'ASTM ' . $c['astm'] : '');
    $fqHead = fn ($c) => \Illuminate\Support\Facades\Lang::has('fiquis.' . $c['key'] . '_head')
        ? __('fiquis.' . $c['key'] . '_head')
        : ($c['unit'] ?: '');

    $safe = fn ($s) => strtr((string) $s, ['₀'=>'0','₁'=>'1','₂'=>'2','₃'=>'3','₄'=>'4','₅'=>'5','₆'=>'6','₇'=>'7','₈'=>'8','₉'=>'9','⁰'=>'0','¹'=>'1','²'=>'2','³'=>'3','⁴'=>'4','↑'=>'','↓'=>'','÷'=>'/','−'=>'-','–'=>'-','≈'=>'~','≤'=>'<=','≥'=>'>=','→'=>' -> ','℃'=>'°C']);
    $GASES = ['h2'=>'H2','ch4'=>'CH4','c2h2'=>'C2H2','c2h4'=>'C2H4','c2h6'=>'C2H6','co'=>'CO','co2'=>'CO2','o2'=>'O2','n2'=>'N2'];
    // N° de informe + laboratorio bajo la fecha de cada muestra (texto chico).
    $sampleMeta = function ($r) use ($safe) {
        $parts = [];
        if (!empty($r['report_number']))  { $parts[] = e($safe($r['report_number'])); }
        if (!empty($r['laboratory_name'])) { $parts[] = e($safe($r['laboratory_name'])); }
        return $parts ? '<br><small style="color:#777">' . implode(' &middot; ', $parts) . '</small>' : '';
    };
    $sub = $transformer->substation; $area = $sub?->area; $loc = $area?->location;
    $age = $transformer->manufacture_year ? (now()->year - (int) $transformer->manufacture_year) : null;

    // Presencia de cada prueba: el informe SOLO incluye las que tienen datos.
    $hasCromas  = count($cromas) > 0;
    $hasFiquis  = count($fiquis) > 0;
    $hasFuranos = count($furanos) > 0;
    $hasFpot    = count($fpots ?? []) > 0;

    // Línea de estado/urgencia que encabeza cada conclusión (escala de acción común).
    $urg = fn ($lvl) => __('diagnostics.urgency_' . ((['routine', 'watch', 'investigate', 'critical'][$lvl] ?? 'routine')));
    // Resalte por severidad (investigar naranja, crítico rojo/negrita); rutina/seguimiento normal.
    $urgStyle = fn ($lvl) => match ((int) $lvl) {
        3 => 'color:#C8281D;font-weight:bold',
        2 => 'color:#E2661E;font-weight:bold',
        default => '',
    };
    // Redacción del informe (diagnósticos + conclusiones por prueba). Vive en
    // App\Support\Transformers\ReportNarrative para que el informe en Word
    // imprima EXACTAMENTE las mismas frases y no se desincronicen.
    $narr = \App\Support\Transformers\ReportNarrative::build($summary);
    $vc = $summary['cromas'] ?? [];  $cDiag = $narr['cromas']['diag'];  $cConcl = $narr['cromas']['concl'];
    $vf = $summary['fiquis'] ?? [];  $fDiag = $narr['fiquis']['diag'];  $fConcl = $narr['fiquis']['concl'];
    $vu = $summary['furanos'] ?? []; $uDiag = $narr['furanos']['diag'];
    $vp = $summary['fpot'] ?? [];    $pDiag = $narr['fpot']['diag'];    $pConcl = $narr['fpot']['concl'];

    $chartsFor = fn ($g) => collect($charts ?? [])->where('group', $g);

    // ¿El valor fisicoquímico excede su límite (según dirección Máx/Mín)?
    $fOver = function ($key, $val) use ($fiquisLimits) {
        $fl = $fiquisLimits[$key] ?? null;
        if (!$fl || $val === null) return false;
        return $fl['dir'] === 'max' ? (float) $val > $fl['value'] : (float) $val < $fl['value'];
    };

    // Numeración dinámica de secciones (solo cuentan las presentes).
    $secN = 0; $sec = function () use (&$secN) { return ++$secN; };
@endphp

    {{-- ── CARÁTULA (página completa) ── --}}
    <div class="cover">
        @if (($reviewState ?? null) === 'in_review')
        <div class="review-banner">{{ __('approvals.pdf_in_review') }}</div>
        <div class="review-note">{{ __('approvals.pdf_in_review_note') }}</div>
        @endif
        <div class="cover__brand">
            @if ($tenantLogo)<img src="{{ $tenantLogo }}">@else <div class="cover__company">{{ $tenant?->name ?: \App\Models\Setting::get('app.name', '') }}</div>@endif
        </div>
        <div class="cover__title">{{ __('transformers.report.title') }}</div>
        <hr class="cover__rule">
        <div class="cover__subject">{{ $transformer->serial ?: $transformer->tag }}</div>
        <div class="cover__sub">@if ($transformer->customer?->name){{ $transformer->customer->name }}@endif @if ($loc)· {{ $loc->name }}@endif</div>

        {{-- Dashboard ejecutivo: gauge del índice de salud + veredicto por prueba. --}}
        <table class="cover__dash"><tr>
            <td class="gcell">
                @if (!empty($hiGauge))
                    <img class="higauge" src="{{ $hiGauge }}">
                @else
                    <div class="hicircle" style="background: {{ $hi['index'] !== null ? $hex($hi['color']) : '#B6BCC4' }}">
                        <div class="hicircle__n">{{ $hi['index'] !== null ? round($hi['index']) . '%' : '—' }}</div>
                        @if ($hi['condition'])<div class="hicircle__c">{{ $cond($hi['condition']) }}</div>@endif
                    </div>
                @endif
                <div class="hicircle__cap">{{ __('transformers.report.health_index') }}</div>
            </td>
            <td class="vcell">
                <table class="vlist">
                    @if ($hasCromas)
                    <tr><td class="vn">{{ __('cromas.tab') }}</td>
                        <td>@if ($vc['condition'] ?? null)<span class="pill pill--lg" style="background: {{ $hex($vc['color'] ?? null) }}">{{ $cond($vc['condition']) }}</span>@else — @endif</td>
                        <td class="vm">DGAF {{ $vc['score'] ?? '—' }}</td></tr>
                    @endif
                    @if ($hasFiquis)
                    <tr><td class="vn">{{ __('fiquis.tab') }}</td>
                        <td>@if ($vf['condition'] ?? null)<span class="pill pill--lg" style="background: {{ $hex($vf['color'] ?? null) }}">{{ $cond($vf['condition']) }}</span>@else — @endif</td>
                        <td class="vm">{{ ($vf['class'] ?? null) ? $safe(__('fiquis.diag.class_' . $vf['class'])) : '' }}</td></tr>
                    @endif
                    @if ($hasFuranos)
                    <tr><td class="vn">{{ __('furanos.tab') }}</td>
                        <td>@if ($vu['condition'] ?? null)<span class="pill pill--lg" style="background: {{ $hex($vu['color'] ?? null) }}">{{ $cond($vu['condition']) }}</span>@else — @endif</td>
                        <td class="vm">@if (($vu['dp'] ?? null) !== null)DP {{ $vu['dp'] }}@endif</td></tr>
                    @endif
                    @if ($hasFpot)
                    <tr><td class="vn">{{ __('fpot.tab') }}</td>
                        <td>@if ($vp['condition'] ?? null)<span class="pill pill--lg" style="background: {{ $hex($vp['color'] ?? null) }}">{{ $cond($vp['condition']) }}</span>@else — @endif</td>
                        <td class="vm">@if (($vp['value'] ?? null) !== null){{ $vp['value'] }} %@endif</td></tr>
                    @endif
                    @if (!$hasCromas && !$hasFiquis && !$hasFuranos && !$hasFpot)
                    <tr><td class="vm">{{ __('diagnostics.no_samples') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr></table>

        <table class="cover__meta">
            <tr><td class="k">{{ __('transformers.report.code') }}</td><td>{{ $reportCode ?? '—' }}</td></tr>
            <tr><td class="k">{{ __('transformers.serial') }}</td><td>{{ $transformer->serial ?: $transformer->tag ?: '—' }}</td></tr>
            <tr><td class="k">{{ __('transformers.report.generated') }}</td><td>{{ $generatedAt->format('d-m-Y H:i') }}</td></tr>
            <tr><td class="k">{{ __('transformers.report.generated_by') }}</td><td>{{ $generatedBy ?: '—' }}</td></tr>
        </table>

        {{-- ── FIRMAS (primera hoja): bloque formal de N firmantes (de a 2 por
             fila), cada uno con su relación (Aprobado/Revisado/…), nombre y
             cargo. La imagen se estampa SOLO si el firmante tiene firma cargada
             Y activó auto-firma; si no, queda la línea para firma a mano. En
             estado EN REVISIÓN no sale ninguna imagen (todavía no firman). NO
             hay slot del que emite: todos los firmantes vienen configurados. ── --}}
        @php $sg = $signers ?? []; $singleSigner = count($sg) === 1; @endphp
        @if (count($sg))
        <table class="sign">
            @foreach (array_chunk($sg, 2) as $pair)
            <tr>
                {{-- 1 solo firmante → se centra (relleno 25% a cada lado). Con 2+
                     va izquierda/derecha; el último impar se rellena a la derecha. --}}
                @if ($singleSigner)<td style="width:25%"></td>@endif
                @foreach ($pair as $s)
                <td @if ($singleSigner) style="width:50%" @endif>
                    @if (!empty($s['signature']))<div class="sign__img"><img src="{{ $s['signature'] }}"></div>
                    @elseif (!empty($s['approved_at']))<div class="sign__stamp">{{ $safe(__('transformers.report.signed_electronically')) }}: {{ $s['approved_at'] }}</div>@endif
                    <div class="sign__line" @if (!empty($s['signature']) || !empty($s['approved_at'])) style="margin-top: 2px;" @endif>
                        <div class="sign__role">{{ $safe($s['relation'] ?? '') }}</div>
                        <div class="sign__name">{{ $s['name'] ?: ' ' }}</div>
                        @if (!empty($s['title']))<div class="sign__title">{{ $safe($s['title']) }}</div>@endif
                    </div>
                </td>
                @endforeach
                @if ($singleSigner)<td style="width:25%"></td>@elseif (count($pair) === 1)<td></td>@endif
            </tr>
            @endforeach
        </table>
        @endif

        @if ($customerLogo)<div class="cover__client"><img src="{{ $customerLogo }}"></div>@endif
        @if (!empty($verifyCode))
        <div class="cover__verify">
            @if (!empty($verifyQr))<img class="cover__qr" src="{{ $verifyQr }}"><br>@endif
            {{ __('transformers.report.verify_code') }}: <b>{{ $verifyCode }}</b>
        </div>
        @endif
    </div>

    {{-- Encabezado compacto en las páginas de contenido --}}
    <table class="hdr"><tr>
        <td style="text-align:left">@if ($tenantLogo)<img class="hdr__logo" src="{{ $tenantLogo }}">@else <span class="hdr__company">{{ $tenant?->name ?: \App\Models\Setting::get('app.name', '') }}</span>@endif</td>
        <td style="text-align:right">@if ($customerLogo)<img class="hdr__logo" src="{{ $customerLogo }}">@endif</td>
    </tr></table>

    <table class="tband"><tr>
        <td>
            <div class="t">{{ __('transformers.report.title') }}</div>
            <div class="s">{{ $transformer->serial ?: $transformer->tag }} @if ($transformer->customer?->name)· {{ $transformer->customer->name }}@endif</div>
        </td>
    </tr></table>

    {{-- El resumen ejecutivo (gauge + veredictos) vive en la carátula; el cuerpo
         arranca directo en las secciones para no duplicarlo. --}}

    {{-- Leyenda del semáforo (clave de lectura de los colores del informe) --}}
    <div class="legend">
        <span class="legend__cap">{{ __('diagnostics.legend') }}</span>
        @foreach (['muy_bueno' => 'green', 'bueno' => 'lime', 'medio' => 'yellow', 'malo' => 'orange', 'muy_malo' => 'red'] as $k => $col)
            <span class="pill" style="background: {{ $hex($col) }}">{{ $condByKey['cond_' . $k] ?? __('diagnostics.cond_' . $k) }}</span>
        @endforeach
    </div>

    <div class="ssec">{{ $sec() }}. {{ __('transformers.report.client') }}</div>
    <table class="kv">
        <tr><td class="k">{{ __('transformers.report.customer') }}</td><td>{{ $transformer->customer->name ?? '—' }}</td>
            <td class="k">{{ __('transformers.report.ruc') }}</td><td>{{ $transformer->customer->cod ?? '—' }}</td></tr>
        <tr><td class="k">{{ __('transformers.report.location') }}</td><td>{{ $loc->name ?? '—' }}</td>
            <td class="k">{{ __('transformers.report.country') }}</td><td>{{ $transformer->customer->country->name ?? '—' }}</td></tr>
        <tr><td class="k">{{ __('transformers.report.area') }}</td><td>{{ $area->name ?? '—' }}</td>
            <td class="k">{{ __('transformers.report.substation') }}</td><td>{{ $sub->name ?? '—' }}</td></tr>
        <tr><td class="k">{{ __('transformers.report.address') }}</td><td colspan="3">{{ $transformer->customer->address ?? '—' }}</td></tr>
    </table>

    <div class="ssec">{{ $sec() }}. {{ __('transformers.report.equipment') }}</div>
    <table class="kv">
        <tr><td class="k">{{ __('transformers.serial') }}</td><td>{{ $transformer->serial ?: '—' }}</td>
            <td class="k">{{ __('transformers.voltage_kv') }}</td><td>{{ $transformer->voltage_kv ?? '—' }}</td>
            <td class="k">{{ __('transformers.transformer_type') }}</td><td>{{ $transformer->transformerType->name ?? '—' }}</td></tr>
        <tr><td class="k">{{ __('transformers.tag') }}</td><td>{{ $transformer->tag ?: '—' }}</td>
            <td class="k">{{ __('transformers.power_mva') }}</td><td>{{ $transformer->power_mva ?? '—' }}</td>
            <td class="k">{{ __('transformers.oil_type') }}</td><td>{{ $transformer->oilType->name ?? '—' }}</td></tr>
        <tr><td class="k">{{ __('transformers.brand') }}</td><td>{{ $transformer->brand->name ?? '—' }}</td>
            <td class="k">{{ __('transformers.phases') }}</td><td>{{ $transformer->phases ? __('transformers.phases_' . $transformer->phases) : '—' }}</td>
            <td class="k">{{ __('transformers.tap_changer_type') }}</td><td>{{ $transformer->tapChangerType->name ?? '—' }}</td></tr>
        <tr><td class="k">{{ __('transformers.report.age') }}</td><td>{{ $age ?? '—' }}</td>
            <td class="k">{{ __('transformers.connection_type') }}</td><td>{{ $transformer->connectionType->name ?? '—' }}</td>
            <td class="k">{{ __('transformers.preservation') }}</td><td>{{ $transformer->preservation->name ?? '—' }}</td></tr>
        @php
            // Regla del conmutador (misma que el Form y la ficha): DETC → marca +
            // modelo; OLTC → además tecnología; sin tipo → nada. Y datos opcionales
            // (papel aislante, último tratamiento de aceite): SOLO si tienen valor.
            // Se arma una lista de pares [etiqueta, valor] y se pinta de a 3 por
            // fila, rellenando celdas vacías para no romper la grilla kv.
            $tapCode = strtolower($transformer->tapChangerType->code ?? '');
            $tapDetails = in_array($tapCode, ['detc', 'oltc'], true);
            $extraPairs = [];
            if ($tapDetails && $transformer->tapChangerBrand) $extraPairs[] = [__('transformers.tap_changer_brand'), $transformer->tapChangerBrand->name];
            if ($tapDetails && $transformer->tapChangerModel) $extraPairs[] = [__('transformers.tap_changer_model'), $transformer->tapChangerModel->name];
            if ($tapCode === 'oltc' && $transformer->tapChangerTechnology) $extraPairs[] = [__('transformers.tap_changer_technology'), $transformer->tapChangerTechnology->name];
            if ($transformer->paper_type) $extraPairs[] = [__('transformers.paper_type'), __('transformers.paper_type_' . $transformer->paper_type)];
            if ($transformer->oil_treated_at) $extraPairs[] = [__('transformers.oil_treated_at'), $transformer->oil_treated_at->format('d/m/Y')];
        @endphp
        @foreach (collect($extraPairs)->chunk(3) as $row)
        <tr>@foreach ($row as [$k, $v])<td class="k">{{ $k }}</td><td>{{ $safe((string) $v) }}</td>@endforeach @for ($i = count($row); $i < 3; $i++)<td class="k"></td><td></td>@endfor</tr>
        @endforeach
    </table>

    {{-- ── METODOLOGÍA: normas/criterios de las pruebas presentes ── --}}
    @if ($hasCromas || $hasFiquis || $hasFuranos || $hasFpot)
    <div class="ssec">{{ $sec() }}. {{ __('transformers.report.methodology') }}</div>
    <ul class="narr">
        @if ($hasCromas)<li>{{ $safe(__('transformers.report.method_cromas', ['standard' => $cromasStandard ?? '—'])) }}</li>@endif
        @if ($hasFiquis)<li>{{ $safe(__('transformers.report.method_fiquis')) }}</li>@endif
        @if ($hasFuranos)<li>{{ $safe(__('transformers.report.method_furanos')) }}</li>@endif
        @if ($hasFpot)<li>{{ $safe(__('transformers.report.method_fpot')) }}</li>@endif
        @if ($hi['index'] !== null)<li>{{ $safe(__('transformers.report.method_hi')) }}</li>@endif
    </ul>
    @endif

    {{-- ── CROMAS (solo si hay datos) ── --}}
    @if ($hasCromas)
    <div class="ssec">{{ $sec() }}. {{ __('transformers.report.dga') }}</div>
        @if (count($cDiag))<ul class="narr">@foreach ($cDiag as $l)<li>{{ $safe($l) }}</li>@endforeach</ul>@endif
        <table class="data">
            <thead><tr><th>{{ __('cromas.sample_date') }}</th>@foreach ($GASES as $g => $lbl)<th>{{ __('cromas.' . $g . '_short') }}<span class="sym">{{ $lbl }}</span><small>{{ __('cromas.gas_unit') }}</small>@if (($gasLimits[$g] ?? null) !== null)<span class="lim">Máx {{ $gasLimits[$g] }}</span>@else<span class="lim">&nbsp;</span>@endif</th>@endforeach<th>{{ __('cromas.state') }}</th></tr></thead>
            <tbody>@foreach ($cromas as $r)<tr><td>{{ $r['sample_date'] }}{!! $sampleMeta($r) !!}</td>@foreach ($GASES as $g => $lbl)<td @class(['over' => ($gasLimits[$g] ?? null) !== null && $r[$g] !== null && (float) $r[$g] > (float) $gasLimits[$g]])>{{ $r[$g] ?? '—' }}</td>@endforeach
                <td>@if ($r['condition'])<span class="pill" style="background: {{ $hex($r['color']) }}">{{ $cond($r['condition']) }}</span>@else — @endif</td></tr>@endforeach</tbody>
        </table>
        {{-- Tendencias: un gráfico por gas (con sus franjas de límite), de a dos por fila. --}}
        {{-- Con >10 muestras la tendencia va ANCHO COMPLETO (1 por fila) — misma
             regla que la pantalla (GasTrends manySamples): 2 por fila aplasta el
             eje de tiempo y no se lee. --}}
        @php $cromasWide = count($cromas) > 10; @endphp
        @if ($chartsFor('cromas')->count())<table class="charts">@foreach ($chartsFor('cromas')->chunk($cromasWide ? 1 : 2) as $pair)<tr>@foreach ($pair as $c)<td @if ($cromasWide) style="width:100%" @endif>@if (!empty($c['label']))<div class="chart-cap">{{ $safe($c['label']) }}</div>@endif<img src="{{ $c['dataURL'] }}"></td>@endforeach</tr>@endforeach</table>@endif
        @if ($chartsFor('duval')->count())
            <div class="ssec">{{ $secN }}.1 {{ __('cromas.duval_tab') }}</div>
            {{-- Veredicto: dónde cayó la muestra y qué significa. Server-side (summary),
                 NO depende de la captura del navegador → sale siempre. --}}
            @php $dvc = $summary['cromas'] ?? []; @endphp
            <ul class="narr">
                @if (!empty($dvc['duval_tri_zone']))<li><strong>{{ __('cromas.duval_tri') }} 1:</strong> {{ $safe(__('cromas.zone.' . $dvc['duval_tri_zone'])) }}</li>@endif
                @if (!empty($dvc['duval_pent_zone']))<li><strong>{{ __('cromas.duval_pent') }} 1:</strong> {{ $safe(__('cromas.zone.' . $dvc['duval_pent_zone'])) }}</li>@endif
            </ul>
            <table class="charts">@foreach ($chartsFor('duval')->chunk(2) as $pair)<tr>@foreach ($pair as $c)<td style="text-align:center;">@if (!empty($c['label']))<div class="chart-cap">{{ $safe($c['label']) }}</div>@endif<img src="{{ $c['dataURL'] }}" style="width:auto; max-width:260px; max-height:260px;"></td>@endforeach</tr>@endforeach</table>
        @endif
        {{-- Relaciones de gases (Rogers + Doernenburg) de la última muestra. Como
             en la UI: solo emiten veredicto con falla activa (IEC 60599). --}}
        @php $latestRatios = $cromas[0]['ratios'] ?? null; $latestFault = (bool) ($cromas[0]['active_fault'] ?? false); @endphp
        @if ($latestRatios)
            <div class="ssec">{{ $secN }}.{{ $chartsFor('duval')->count() ? 2 : 1 }} {{ __('transformers.report.ratio_methods') }}</div>
            @unless ($latestFault)<ul class="narr"><li>{{ $safe(__('cromas.ratios.no_fault')) }}</li></ul>@endunless
            <table class="data">
                <thead><tr><th style="text-align:left">{{ __('transformers.report.ratio_method') }}</th>
                    <th>{{ __('transformers.report.ratio_values') }}</th><th>{{ __('cromas.ratios.verdict') }}</th></tr></thead>
                <tbody>
                @foreach (['rogers', 'doernenburg'] as $m)
                    @php $rm = $latestRatios[$m] ?? null; @endphp
                    @if ($rm)
                    <tr>
                        <td style="text-align:left; font-weight:bold">{{ __('cromas.ratios.' . $m) }}</td>
                        <td>@foreach ($rm['ratios'] as $rr){{ $safe($rr['label']) }} = {{ $rr['value'] ?? '—' }}@if (!$loop->last)<br>@endif @endforeach</td>
                        <td>{{ $latestFault && $rm['complete'] && $rm['fault'] ? $safe(__('cromas.ratios.fault.' . $rm['fault'])) : __('cromas.ratios.na') }}</td>
                    </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        @endif

        {{-- IEEE C57.104-2019 — Estado DGA (Tablas 1-4). Motor en datos; aquí solo
             se presenta el veredicto y el detalle de la Tabla 4 (tasa). --}}
        @if (!empty($dgaStatus))
            @php
                $dg = $dgaStatus;
                $stHex = $dg['status'] === 1 ? '#1D7044' : ($dg['status'] === 2 ? '#E9A23B' : '#C8281D');
                $tHex = fn ($v) => $v === 'ok' ? '#1D7044' : ($v === 'bad' ? '#C8281D' : '#9AA0A6');
                $tLbl = fn ($v) => $v === 'ok' ? __('transformers.ieee_dga.pdf_ok') : ($v === 'bad' ? __('transformers.ieee_dga.pdf_bad') : __('transformers.ieee_dga.pdf_na'));
                $t1 = $dg['table1_ok'] === true ? 'ok' : ($dg['table1_ok'] === false ? 'bad' : 'na');
                $t2 = ($dg['table2_exceeded'] ?? false) ? 'bad' : 'ok';
                $t3 = $dg['table3_ok'] === true ? 'ok' : ($dg['table3_ok'] === false ? 'bad' : 'na');
                $t4 = $dg['table4_ok'] === true ? 'ok' : ($dg['table4_ok'] === false ? 'bad' : 'na');
                $colLbl = ($dg['column'] ?? 'le02') === 'gt02' ? __('transformers.ieee_dga.col_gt02') : __('transformers.ieee_dga.col_le02');
                $byId = [];
                foreach (($dg['samples'] ?? []) as $s) { $byId[$s['id']] = $s['date']; }
                $usedDates = [];
                foreach (($dg['rate']['sample_ids'] ?? []) as $sid) { if (isset($byId[$sid])) { $usedDates[] = $byId[$sid]; } }
                $ieeeSub = 1 + ($chartsFor('duval')->count() ? 1 : 0) + ($latestRatios ? 1 : 0);
            @endphp
            <div class="ssec">{{ $secN }}.{{ $ieeeSub }} {{ __('transformers.ieee_dga.pdf_title') }}</div>
            <ul class="narr"><li><strong>{{ $safe($dg['label']) }}:</strong> {{ $safe($dg['text']) }}</li></ul>
            <table class="data">
                <thead><tr><th>{{ __('transformers.ieee_dga.table') }} 1</th><th>{{ __('transformers.ieee_dga.table') }} 2</th><th>{{ __('transformers.ieee_dga.table') }} 3</th><th>{{ __('transformers.ieee_dga.table') }} 4</th></tr></thead>
                <tbody><tr>
                    @foreach ([$t1, $t2, $t3, $t4] as $tv)
                        <td><span class="pill" style="background: {{ $tHex($tv) }}">{{ $tLbl($tv) }}</span></td>
                    @endforeach
                </tr></tbody>
            </table>
            @if (!empty($dg['table4_applicable']))
                <table class="kv">
                    <tr><td class="k">{{ $safe(__('transformers.ieee_dga.column_label')) }}</td><td>{{ $safe($colLbl) }}</td></tr>
                    <tr><td class="k">{{ __('transformers.ieee_dga.period') }}</td><td>{{ $dg['rate']['period_months'] }} {{ __('transformers.ieee_dga.months') }}</td></tr>
                    <tr><td class="k">{{ __('transformers.ieee_dga.used_samples') }}</td><td>{{ $dg['rate']['samples'] }}@if (count($usedDates)) — {{ implode(' · ', $usedDates) }}@endif</td></tr>
                </table>
                <table class="data">
                    <thead><tr><th>Gas</th><th>{{ __('transformers.ieee_dga.per_year') }}</th><th>{{ __('transformers.ieee_dga.per_day') }}</th><th>{{ __('cromas.state') }}</th></tr></thead>
                    <tbody>
                    @foreach (($dg['rate']['per_gas'] ?? []) as $g => $x)
                        <tr>
                            <td>{{ strtoupper($g) }}</td>
                            <td @class(['over' => $x['exceeded'] ?? false])>{{ $x['rate'] ?? '—' }}@if (($x['limit'] ?? null) !== null)<span class="lim">Máx {{ $x['limit'] }}</span>@endif</td>
                            <td>{{ $x['rate_day'] ?? '—' }}</td>
                            <td>{{ ($x['exceeded'] ?? false) ? $safe(__('transformers.ieee_dga.pdf_bad')) : __('transformers.ieee_dga.pdf_ok') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            <p style="font-size:7.5pt; color:#94A3B8; margin:4px 0 2px;">{{ $safe($dg['source']) }}</p>
        @endif
    @endif

    {{-- ── FIQUIS (solo si hay datos) ── --}}
    @if ($hasFiquis)
    <div class="ssec">{{ $sec() }}. {{ __('transformers.report.oil_quality') }}</div>
        @if (count($fDiag))<ul class="narr">@foreach ($fDiag as $l)<li>{{ $safe($l) }}</li>@endforeach</ul>@endif
        <table class="data">
            <thead><tr><th>{{ __('fiquis.sample_date') }}</th>@foreach ($fiquisColumns as $c)<th>{{ __('fiquis.' . $c['key']) }}<span class="sym method">{{ $safe($fqAstm($c)) }}</span><small>{{ $safe($fqHead($c)) }}</small>@if (isset($fiquisLimits[$c['key']]))<span class="lim">{{ $fiquisLimits[$c['key']]['dir'] === 'max' ? 'Máx' : 'Mín' }} {{ $fiquisLimits[$c['key']]['value'] }}</span>@else<span class="lim">&nbsp;</span>@endif</th>@endforeach<th>{{ __('fiquis.state') }}</th></tr></thead>
            <tbody>@foreach ($fiquis as $r)<tr><td>{{ $r['sample_date'] }}{!! $sampleMeta($r) !!}</td>@foreach ($fiquisColumns as $c)<td @class(['over' => $fOver($c['key'], $r[$c['key']] ?? null)])>{{ $r[$c['key']] ?? '—' }}</td>@endforeach
                <td>@if ($r['condition'])<span class="pill" style="background: {{ $hex($r['color']) }}">{{ $cond($r['condition']) }}</span>@else — @endif</td></tr>@endforeach</tbody>
        </table>
        {{-- Misma regla que cromas: con >10 muestras, 1 tendencia por fila. --}}
        @php $fiquisWide = count($fiquis) > 10; @endphp
        @if ($chartsFor('fiquis')->count())<table class="charts">@foreach ($chartsFor('fiquis')->chunk($fiquisWide ? 1 : 2) as $pair)<tr>@foreach ($pair as $c)<td @if ($fiquisWide) style="width:100%" @endif>@if (!empty($c['label']))<div class="chart-cap">{{ $safe($c['label']) }}</div>@endif<img src="{{ $c['dataURL'] }}"></td>@endforeach</tr>@endforeach</table>@endif
    @endif

    {{-- ── FURANOS (solo si hay datos) ── --}}
    @if ($hasFuranos)
    <div class="ssec">{{ $sec() }}. {{ __('furanos.tab') }}</div>
        @if (count($uDiag))<ul class="narr">@foreach ($uDiag as $l)<li>{{ $safe($l) }}</li>@endforeach</ul>@endif
        <table class="data">
            <thead><tr><th>{{ __('furanos.sample_date') }}</th><th>2FAL (ppb)</th><th>{{ __('furanos.dp') }}</th><th>{{ __('furanos.state') }}</th></tr></thead>
            <tbody>@foreach ($furanos as $r)<tr><td>{{ $r['sample_date'] }}{!! $sampleMeta($r) !!}</td><td>{{ $r['fal'] ?? '—' }}</td><td>{{ $r['dp'] ?? '—' }}</td>
                <td>@if ($r['condition'])<span class="pill" style="background: {{ $hex($r['color']) }}">{{ $cond($r['condition']) }}</span>@else — @endif</td></tr>@endforeach</tbody>
        </table>
        {{-- Tendencia del DP (vida del papel): cae = el papel envejece.
             Centrado y acotado (un solo gráfico a página completa se ve gigante). --}}
        @if ($chartsFor('furanos')->count())
        {{-- Furanos ya va 1 por fila; con >10 muestras suelta el tope de 340px
             y usa el ancho completo (misma regla que cromas/fiquis). --}}
        <table class="charts">@foreach ($chartsFor('furanos') as $c)<tr><td style="text-align:center; width:100%;">@if (!empty($c['label']))<div class="chart-cap">{{ $safe($c['label']) }}</div>@endif<img src="{{ $c['dataURL'] }}" style="{{ count($furanos) > 10 ? 'width:100%;' : 'width:auto; max-width:340px; max-height:200px;' }}"></td></tr>@endforeach</table>
        @endif
    @endif

    {{-- ── FACTOR DE POTENCIA (solo si hay datos) ── --}}
    @if ($hasFpot)
    <div class="ssec">{{ $sec() }}. {{ __('fpot.tab') }}</div>
        @if (count($pDiag))<ul class="narr">@foreach ($pDiag as $l)<li>{{ $safe($l) }}</li>@endforeach</ul>@endif
        <table class="data">
            <thead><tr><th>{{ __('fpot.sample_date') }}</th><th>{{ __('fpot.value') }}</th><th>{{ __('fpot.temperature') }}</th><th>{{ __('fpot.state') }}</th></tr></thead>
            <tbody>@foreach ($fpots as $r)<tr><td>{{ $r['sample_date'] }}{!! $sampleMeta($r) !!}</td><td>{{ $r['value'] ?? '—' }}</td><td>{{ $r['temperature'] ?? '—' }}</td>
                <td>@if ($r['condition'])<span class="pill" style="background: {{ $hex($r['color']) }}">{{ $cond($r['condition']) }}</span>@else — @endif</td></tr>@endforeach</tbody>
        </table>
    @endif

    {{-- ── CONCLUSIONES (solo de pruebas presentes) ── --}}
    <div class="ssec">{{ $sec() }}. {{ __('transformers.report.conclusions') }}</div>
    <div class="reco">
        @if (count($cConcl))<p><b>{{ __('cromas.tab') }}:</b> <span style="{{ $urgStyle($vc['action_level'] ?? 0) }}">{{ $safe($urg($vc['action_level'] ?? 0)) }}</span> {{ $safe(implode(' ', $cConcl)) }}</p>@endif
        @if (count($fConcl))<p><b>{{ __('fiquis.tab') }}:</b> <span style="{{ $urgStyle($vf['action_level'] ?? 0) }}">{{ $safe($urg($vf['action_level'] ?? 0)) }}</span> {{ $safe(implode(' ', $fConcl)) }}</p>@endif
        @if ($hasFuranos && ($vu['condition'] ?? null) !== null)
            <p><b>{{ __('furanos.tab') }}:</b> <span style="{{ $urgStyle($vu['action_level'] ?? 0) }}">{{ $safe($urg($vu['action_level'] ?? 0)) }}</span> {{ $safe(__('furanos.' . ['reco_routine', 'reco_monitor', 'reco_increase', 'reco_critical'][$vu['action_level'] ?? 0])) }}</p>
        @endif
        @if ($hasFpot && ($vp['condition'] ?? null) !== null)
            <p><b>{{ __('fpot.tab') }}:</b> <span style="{{ $urgStyle($vp['action_level'] ?? 0) }}">{{ $safe($urg($vp['action_level'] ?? 0)) }}</span> {{ $safe(implode(' ', $pConcl)) }}</p>
        @endif
        @if (!count($cConcl) && !count($fConcl) && !$hasFuranos && !$hasFpot)<p>—</p>@endif
    </div>

    @include('business_management.transformers.pdf._report_disclaimer')
    @include('business_management.transformers.pdf._report_footer')
</body>
</html>
