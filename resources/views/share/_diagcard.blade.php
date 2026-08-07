@php
    $CK = ['Muy Bueno' => 'muy_bueno', 'Bueno' => 'bueno', 'Medio' => 'medio', 'Malo' => 'malo', 'Muy Malo' => 'muy_malo'];
    $HEX = ['green' => '#1D7044', 'lime' => '#5AA82E', 'yellow' => '#E9A23B', 'orange' => '#E2661E', 'red' => '#C8281D'];
    $cond = fn ($c) => \App\Support\Diagnostics\ConditionLabel::forCondition($c);
    $hex = fn ($c) => $HEX[$c] ?? '#9aa0a6';
    $num = fn ($v) => $v === null || $v === '' ? '—' : $v;
    // Nombre de la prueba traducido por código (tests.name es solo español);
    // si no hay clave, cae al nombre de la BD.
    $testName = fn ($code, $fallback) => $code && \Illuminate\Support\Facades\Lang::has("diagnostics.test_{$code}")
        ? __("diagnostics.test_{$code}") : ($fallback ?? $code ?? '—');
@endphp
<div class="card">
    <h1 class="h1">{{ $summary['serial'] ?: $summary['tag'] }}</h1>
    <p class="sub">
        {{ $summary['customer'] ?? '—' }}
        @if ($summary['type']) · {{ $summary['type'] }} @endif
        @if ($summary['oil']) · {{ $summary['oil'] }} @endif
    </p>

    <div class="kpis">
        <div class="kpi">
            <b>{{ $summary['index'] !== null ? $summary['index'] : '—' }}</b>
            <span>{{ __('transformers.health_index') }}</span>
        </div>
        <div class="kpi">
            <b><span class="dot" style="background: {{ $hex($summary['color']) }}"></span>{{ $cond($summary['condition']) }}</b>
            <span>{{ __('transformers.health_state') }}</span>
        </div>
        <div class="kpi"><b>{{ $num($summary['voltage_kv']) }}</b><span>kV</span></div>
        <div class="kpi"><b>{{ $num($summary['power_mva']) }}</b><span>MVA</span></div>
    </div>

    <table class="list">
        <thead><tr><th>{{ __('sharing.test') }}</th><th>{{ __('furanos.state') }}</th></tr></thead>
        <tbody>
            @foreach ($summary['components'] as $c)
                <tr>
                    <td>{{ $testName($c['code'] ?? null, $c['name'] ?? null) }}</td>
                    <td>
                        @if (!empty($c['present']) && !empty($c['condition']))
                            <span class="pill" style="background: {{ $hex($c['color'] ?? null) }}">{{ $cond($c['condition']) }}</span>
                        @else
                            <span class="muted">{{ __('sharing.no_data') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @isset($pdfUrl)
        <p style="margin:18px 0 0;"><a href="{{ $pdfUrl }}" class="btn">{{ __('sharing.download_pdf') }}</a></p>
    @endisset
</div>
