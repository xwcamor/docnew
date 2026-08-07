<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /*
         | Reglas para PDFs en DomPDF (cosas que la documentación no aclara y
         | nos pegamos varios palos):
         |
         | 1) font-family: solo "Helvetica" (PDF core font). No usar "sans-serif"
         |    ni "DejaVu Sans" — DomPDF las puede tener pero requieren config
         |    extra y rompen el render.
         | 2) font-weight: SOLO `normal` o `bold`. Valores numéricos como 600
         |    quedan literales y la registry de fonts no los conoce.
         | 3) Evitar `position: fixed` con offsets negativos para hdrs/footers.
         |    DomPDF lo soporta pero a veces explota el layout de tablas
         |    siguientes (filas que se estiran a página completa).
         | 4) `display: inline-block` con `background-color` puede estirarse
         |    como si fuera block en algunas celdas. Para "badges" usar texto
         |    coloreado plano (status-active / status-inactive).
         */

        @page {
            margin: 24px 28px 24px 28px;
        }

        body {
            font-family: Helvetica;
            font-size: 9pt;
            color: #32363A;
            margin: 0;
        }

        /* ── Brand band (header del reporte, solo aparece arriba en p.1) ── */
        .brand-band {
            background: #354A5F;
            color: #ffffff;
            padding: 14px 18px;
            margin-bottom: 14px;
        }
        .brand-band__meta {
            float: right;
            font-size: 8pt;
            color: #cbd5e1;
            text-align: right;
            line-height: 1.4;
        }
        .brand-band__meta strong { color: #ffffff; font-weight: bold; }
        .brand-band__title {
            font-size: 14pt;
            font-weight: bold;
            margin: 0;
            letter-spacing: 0.01em;
        }
        .brand-band__sub {
            font-size: 8pt;
            color: #cbd5e1;
            margin: 4px 0 0 0;
        }

        /* ── Filters summary box ─────────────────────────────────────────── */
        .filters {
            background: #F0F6FB;
            border-left: 3px solid #0A6ED1;
            padding: 8px 12px;
            margin: 0 0 12px 0;
            font-size: 8.5pt;
            color: #334155;
        }
        .filters__title {
            display: block;
            font-weight: bold;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #0A6ED1;
            margin: 0 0 4px 0;
        }
        .filters__list { margin: 0; padding: 0; list-style: none; }
        .filters__list li { line-height: 1.5; }
        .filters__list li b { font-weight: bold; color: #1f2937; }

        /* ── Counter line ──────────────────────────────────────────────── */
        .counter {
            font-size: 8.5pt;
            color: #6A6D70;
            margin: 0 0 8px 0;
        }
        .counter strong { color: #1f2937; font-weight: bold; }

        /* ── Data table ─────────────────────────────────────────────────── */
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        table.data thead th {
            background: #0A6ED1;
            color: #ffffff;
            font-weight: bold;
            font-size: 9pt;
            text-align: left;
            padding: 6px 8px;
            border: 1px solid #085CAF;
        }
        table.data tbody td {
            padding: 5px 8px;
            border: 1px solid #E5E5E5;
            font-size: 8.5pt;
            color: #32363A;
        }
        table.data tbody tr:nth-child(even) td {
            background: #F8FAFC;
        }

        /* Status como texto coloreado (no badges con bg — ver nota arriba) */
        .status-active   { color: #1D7044; font-weight: bold; }
        .status-inactive { color: #C8281D; font-weight: bold; }

        /* ── Empty / footer  ────────────────────────────────────────────── */
        .empty {
            text-align: center;
            padding: 32px 20px;
            color: #6A6D70;
            font-size: 9pt;
        }
        .doc-footer {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #E5E5E5;
            font-size: 7.5pt;
            color: #6A6D70;
            text-align: center;
        }
    </style>
</head>
<body>
    {{-- ── Brand band: solo aparece arriba en página 1 (flow normal) ──── --}}
    <div class="brand-band">
        <div class="brand-band__meta">
            <strong>{{ config('app.name') }}</strong><br>
            {{ __('global.created_by') }}: {{ $generatedBy }}
        </div>
        <h1 class="brand-band__title">{{ $title }}</h1>
        <p class="brand-band__sub">
            {{ __('global.generated_at') }}: {{ now()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }}
        </p>
    </div>

    {{-- ── Filtros aplicados (opcional) ───────────────────────────────── --}}
    @if (!empty($filtersSummary))
        <div class="filters">
            <span class="filters__title">{{ __('global.filters_applied') }}</span>
            <ul class="filters__list">
                @foreach ($filtersSummary as $f)
                    <li><b>{{ $f['label'] }}:</b> {{ $f['value'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── Contador de registros ──────────────────────────────────────── --}}
    <p class="counter">
        {{ trans_choice('global.records_in_report', $totalCount, ['count' => $totalCount]) }}
    </p>

    {{-- ── Tabla de datos ─────────────────────────────────────────────── --}}
    @php
        $headings = [
            'id'         => __('locales.id'),
            'name'       => __('locales.name'),
            'code'       => __('locales.code'),
            'language'   => __('locales.language'),
            'is_active'  => __('locales.is_active'),
            'slug'       => 'Slug',
            'created_at' => __('global.created_at'),
            'updated_at' => __('global.updated_at'),
            'creator'    => __('global.created_by'),
        ];
    @endphp

    @if ($totalCount === 0)
        <div class="empty">
            {{ __('global.no_matching_records') }}
        </div>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach ($columns as $col)
                        <th>{{ $headings[$col] ?? $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($locales as $locale)
                    <tr>
                        @foreach ($columns as $col)
                            <td>
                                @switch($col)
                                    @case('id')        {{ $locale->id }} @break
                                    @case('name')      {{ $locale->name }} @break
                                    @case('code')      {{ $locale->code }} @break
                                    @case('language')  {{ $locale->language->name ?? '—' }} @break
                                    @case('is_active')
                                        <span class="{{ $locale->is_active ? 'status-active' : 'status-inactive' }}">
                                            {{ $locale->state_text }}
                                        </span>
                                    @break
                                    @case('slug')       {{ $locale->slug }} @break
                                    @case('created_at') {{ $locale->created_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }} @break
                                    @case('updated_at') {{ $locale->updated_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }} @break
                                    @case('creator')    {{ $locale->creator->name ?? '—' }} @break
                                    @default {{ $locale->{$col} ?? '' }}
                                @endswitch
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="doc-footer">
        {{ config('app.name') }} · {{ now()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATE_FORMAT) }}
    </div>
</body>
</html>
