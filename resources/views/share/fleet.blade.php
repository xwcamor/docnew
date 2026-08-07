@extends('share.layout')
@section('title', __('sharing.fleet_title'))
@section('content')
@php
    $HEX = ['green' => '#1D7044', 'lime' => '#5AA82E', 'yellow' => '#E9A23B', 'orange' => '#E2661E', 'red' => '#C8281D'];
    $CK = ['Muy Bueno' => 'muy_bueno', 'Bueno' => 'bueno', 'Medio' => 'medio', 'Malo' => 'malo', 'Muy Malo' => 'muy_malo'];
    $cond = fn ($c) => \App\Support\Diagnostics\ConditionLabel::forCondition($c);
    $hex = fn ($c) => $HEX[$c] ?? '#9aa0a6';
    // Severidad para ordenar por estado (0 mejor … 4 peor); sin dato al final.
    $SEV = ['Muy Bueno' => 0, 'Bueno' => 1, 'Medio' => 2, 'Malo' => 3, 'Muy Malo' => 4];
    $sevOf = fn ($c) => $SEV[$c] ?? 9;
    // Color fijo por condición (para los chips, sin depender de los datos de fila).
    $COND_COLOR = ['Muy Bueno' => 'green', 'Bueno' => 'lime', 'Medio' => 'yellow', 'Malo' => 'orange', 'Muy Malo' => 'red'];

    // KPIs de la flota.
    $total = count($rows);
    $withHi = collect($rows)->filter(fn ($r) => $r['index'] !== null);
    $avgHi = $withHi->count() ? round($withHi->avg('index')) : null;
    // Conteo por condición, en orden de severidad (para los chips de filtro).
    $byCond = [];
    foreach (array_keys($SEV) as $c) {
        $n = collect($rows)->where('condition', $c)->count();
        if ($n > 0) $byCond[$c] = $n;
    }
    // "Requieren atención" = Malo + Muy Malo (lo que el cliente debe mirar ya).
    $attention = collect($rows)->whereIn('condition', ['Malo', 'Muy Malo'])->count();
@endphp
<div class="card">
    <h1 class="h1">{{ __('sharing.fleet_title') }}</h1>
    <p class="sub">{{ $rows[0]['customer'] ?? '' }} · {{ $total }} {{ __('transformers.plural') }}</p>

    {{-- KPIs --}}
    <div class="kpi-grid">
        <div class="kpicard"><b>{{ $total }}</b><span>{{ __('transformers.plural') }}</span></div>
        <div class="kpicard"><b>{{ $avgHi !== null ? $avgHi : '—' }}</b><span>{{ __('sharing.kpi_avg_hi') }}</span></div>
        <div class="kpicard"><b style="color:{{ $attention ? '#C8281D' : '#1D7044' }}">{{ $attention }}</b><span>{{ __('sharing.kpi_attention') }}</span></div>
        <div class="kpicard"><b style="color:#1D7044">{{ ($byCond['Muy Bueno'] ?? 0) + ($byCond['Bueno'] ?? 0) }}</b><span>{{ __('sharing.kpi_healthy') }}</span></div>
    </div>

    {{-- Filtro por estado (chips clickeables) --}}
    <div class="chips" id="condChips">
        <span class="chip" data-cond="" aria-pressed="true"><b>{{ $total }}</b> {{ __('sharing.kpi_all') }}</span>
        @foreach ($byCond as $c => $n)
            <span class="chip" data-cond="{{ $c }}" aria-pressed="false"><span class="dot" style="background:{{ $hex($COND_COLOR[$c] ?? null) }}"></span><b>{{ $n }}</b> {{ $cond($c) }}</span>
        @endforeach
    </div>

    {{-- Toolbar: buscador + orden --}}
    <div class="toolbar">
        <div class="search"><input type="search" id="fleetSearch" placeholder="{{ __('sharing.search_placeholder') }}" autocomplete="off"></div>
        <select id="fleetSort">
            <option value="sev-asc">{{ __('sharing.sort_worst_first') }}</option>
            <option value="sev-desc">{{ __('sharing.sort_best_first') }}</option>
            <option value="hi-asc">{{ __('sharing.sort_hi_asc') }}</option>
            <option value="hi-desc">{{ __('sharing.sort_hi_desc') }}</option>
            <option value="serial-asc">{{ __('sharing.sort_serial') }}</option>
        </select>
    </div>

    <table class="list" id="fleetTable">
        <thead>
            <tr>
                <th class="sortable" data-sort="serial">{{ __('transformers.serial') }} <span class="arrow">&#8597;</span></th>
                <th>{{ __('transformers.transformer_type') }}</th>
                <th class="sortable" data-sort="hi">{{ __('transformers.health_index') }} <span class="arrow">&#8597;</span></th>
                <th class="sortable" data-sort="sev">{{ __('transformers.health_state') }} <span class="arrow">&#8597;</span></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr data-text="{{ strtolower(($r['serial'] ?: $r['tag']) . ' ' . ($r['type'] ?? '')) }}"
                    data-cond="{{ $r['condition'] }}"
                    data-sev="{{ $sevOf($r['condition']) }}"
                    data-hi="{{ $r['index'] !== null ? $r['index'] : -1 }}"
                    data-serial="{{ strtolower($r['serial'] ?: $r['tag']) }}">
                    <td><strong>{{ $r['serial'] ?: $r['tag'] }}</strong></td>
                    <td>{{ $r['type'] ?? '—' }}</td>
                    <td>
                        <span class="hibar"><i style="width:{{ $r['index'] !== null ? max(3, (int) $r['index']) : 0 }}%; background:{{ $hex($r['color']) }}"></i></span>
                        {{ $r['index'] !== null ? $r['index'] : '—' }}
                    </td>
                    <td><span class="dot" style="background: {{ $hex($r['color']) }}"></span>{{ $cond($r['condition']) }}</td>
                    <td style="white-space:nowrap;">
                        <a href="{{ route('share.transformer', ['token' => $share->token, 'transformer' => $r['id']]) }}" class="btn" style="padding:6px 12px; font-size:12px;">{{ __('sharing.view_detail') }}</a>
                        <a href="{{ route('share.pdf', ['token' => $share->token, 'transformer' => $r['id']]) }}" class="btn--ghost btn" style="padding:6px 12px; font-size:12px;">PDF</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="noresults" id="fleetEmpty" style="display:none;">{{ __('sharing.no_results') }}</div>
</div>

<script>
(function () {
    var table = document.getElementById('fleetTable');
    var tbody = table.querySelector('tbody');
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    var search = document.getElementById('fleetSearch');
    var sortSel = document.getElementById('fleetSort');
    var chips = document.getElementById('condChips');
    var empty = document.getElementById('fleetEmpty');
    var activeCond = '';

    function apply() {
        var q = (search.value || '').toLowerCase().trim();
        var shown = 0;
        rows.forEach(function (r) {
            var okText = !q || r.dataset.text.indexOf(q) !== -1;
            var okCond = !activeCond || r.dataset.cond === activeCond;
            var vis = okText && okCond;
            r.style.display = vis ? '' : 'none';
            if (vis) shown++;
        });
        empty.style.display = shown ? 'none' : 'block';
    }

    function sortBy(key) {
        var parts = key.split('-'); var field = parts[0]; var dir = parts[1] === 'desc' ? -1 : 1;
        var sorted = rows.slice().sort(function (a, b) {
            var av, bv;
            if (field === 'serial') { av = a.dataset.serial; bv = b.dataset.serial; return av < bv ? -dir : av > bv ? dir : 0; }
            av = parseFloat(a.dataset[field]); bv = parseFloat(b.dataset[field]);
            return (av - bv) * dir;
        });
        sorted.forEach(function (r) { tbody.appendChild(r); });
    }

    search.addEventListener('input', apply);
    sortSel.addEventListener('change', function () { sortBy(sortSel.value); });
    chips.addEventListener('click', function (e) {
        var chip = e.target.closest('.chip'); if (!chip) return;
        activeCond = chip.dataset.cond;
        chips.querySelectorAll('.chip').forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
        apply();
    });

    sortBy('sev-asc'); // arranca mostrando los peores primero (lo que el cliente debe atender)
})();
</script>
@endsection
