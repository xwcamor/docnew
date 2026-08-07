<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    @php
        $RATING_COLOR = [4 => '#1D7044', 3 => '#5AA82E', 2 => '#E9A23B', 1 => '#E2661E', 0 => '#C8281D'];
        $condLabel = fn ($r) => \App\Support\Diagnostics\ConditionLabel::for($r);
        $condColor = fn ($r) => $r === null ? '#9aa0a6' : ($RATING_COLOR[$r] ?? '#9aa0a6');
        $L = fn ($k) => __('transformers.fleet_report.' . $k);
    @endphp
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #32363A; font-size: 9px; }
        .head { background: #0A6ED1; color: #fff; padding: 14px 18px; }
        .head h1 { margin: 0; font-size: 16px; font-weight: 700; }
        .head .sub { font-size: 10px; opacity: .92; margin-top: 2px; }
        .wrap { padding: 12px 18px; }
        .cover { margin-bottom: 12px; }
        .kpis { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .kpis td { padding: 6px 8px; border: 1px solid #e5e7eb; text-align: center; }
        .kpis .n { font-size: 14px; font-weight: 700; }
        .kpis .l { font-size: 8px; color: #6A6D70; text-transform: uppercase; }
        .note { background: #FFF7E6; border: 1px solid #FFD591; color: #874D00; padding: 6px 10px; font-size: 9px; border-radius: 3px; margin-bottom: 10px; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #354A5F; color: #fff; font-size: 8px; padding: 5px 4px; text-align: left; }
        table.grid td { border-bottom: 1px solid #eceff2; padding: 4px; font-size: 8px; }
        table.grid tr:nth-child(even) td { background: #f7f9fb; }
        .chip { display: inline-block; padding: 1px 6px; border-radius: 8px; color: #fff; font-weight: 700; font-size: 8px; }
        .mono { font-family: DejaVu Sans Mono, monospace; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $L('title') }}</h1>
        <div class="sub">
            {{ $customerName ?? $L('scope_all') }} ·
            {{ $generatedAt }} ·
            {{ $L('h_serial') }}s: {{ $total }}
        </div>
    </div>

    <div class="wrap">
        {{-- Portada: distribución de salud --}}
        <div class="cover">
            <table class="kpis">
                <tr>
                    @foreach ([4, 3, 2, 1, 0] as $r)
                        <td>
                            <div class="n" style="color: {{ $RATING_COLOR[$r] }}">{{ $dist[$r] ?? 0 }}</div>
                            <div class="l">{{ $condLabel($r) }}</div>
                        </td>
                    @endforeach
                    <td>
                        <div class="n" style="color:#9aa0a6">{{ $dist['sin'] ?? 0 }}</div>
                        <div class="l">{{ $L('sin_dx') }}</div>
                    </td>
                </tr>
            </table>
        </div>

        @if ($capped)
            <div class="note">{{ $L('pdf_capped', ['shown' => $shown, 'total' => $total]) }}</div>
        @endif

        {{-- Detalle: 1 fila por transformador --}}
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ $L('h_serial') }}</th>
                    <th>{{ $L('h_customer') }}</th>
                    <th>{{ $L('h_type') }}</th>
                    <th>{{ $L('h_voltage') }}</th>
                    <th>{{ $L('h_power') }}</th>
                    <th>{{ $L('h_hi') }}</th>
                    <th>{{ $L('h_condition') }}</th>
                    <th>{{ $L('sheet_cromas') }}</th>
                    <th>{{ $L('sheet_furanos') }}</th>
                    <th>{{ $L('sheet_fiquis') }}</th>
                    <th>{{ $L('sheet_fpot') }}</th>
                    <th>{{ $L('h_fault') }}</th>
                    <th>{{ $L('h_ieee') }}</th>
                    <th>{{ $L('h_dp') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td class="mono">{{ $r['serial'] }}</td>
                        <td>{{ $r['customer'] }}</td>
                        <td>{{ $r['type'] }}</td>
                        <td>{{ $r['voltage_kv'] ?? '—' }}</td>
                        <td>{{ $r['power_mva'] ?? '—' }}</td>
                        <td>{{ $r['hi'] ?? '—' }}</td>
                        <td><span class="chip" style="background: {{ $condColor($r['rating']) }}">{{ $condLabel($r['rating']) }}</span></td>
                        <td>{{ $r['cromas'] ?? '—' }}</td>
                        <td>{{ $r['furanos'] ?? '—' }}</td>
                        <td>{{ $r['fiquis'] ?? '—' }}</td>
                        <td>{{ $r['fpot'] ?? '—' }}</td>
                        <td>{{ $r['fault_type'] ?? '—' }}</td>
                        <td>{{ $r['ieee'] ?? '—' }}</td>
                        <td>{{ $r['dp'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
