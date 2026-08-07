<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /*
         | Reglas para PDFs en DomPDF (clon del template de Customers):
         |
         | 1) font-family: solo "Helvetica" (PDF core font).
         | 2) font-weight: SOLO `normal` o `bold`. Numericos como 600 no andan.
         | 3) Evitar `position: fixed` con offsets negativos para hdrs/footers.
         | 4) `display: inline-block` con `background-color` puede estirarse —
         |    para badges usamos texto coloreado plano.
         */

        @page {
            margin: 24px 28px 24px 28px;
        }

        body {
            font-family: Helvetica;
            font-size: 8.5pt;
            color: #32363A;
            margin: 0;
        }

        /* Brand band */
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

        /* Filters summary box */
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

        /* Counter */
        .counter {
            font-size: 8.5pt;
            color: #6A6D70;
            margin: 0 0 8px 0;
        }
        .counter strong { color: #1f2937; font-weight: bold; }

        /* Data table */
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        table.data thead th {
            background: #0A6ED1;
            color: #ffffff;
            font-weight: bold;
            font-size: 8.5pt;
            text-align: left;
            padding: 5px 7px;
            border: 1px solid #085CAF;
        }
        table.data tbody td {
            padding: 4px 7px;
            border: 1px solid #E5E5E5;
            font-size: 8pt;
            color: #32363A;
        }
        table.data tbody tr:nth-child(even) td {
            background: #F8FAFC;
        }

        .status-active   { color: #1D7044; font-weight: bold; }
        .status-inactive { color: #C8281D; font-weight: bold; }

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
    {{-- Brand band: solo aparece arriba en pagina 1 (flow normal) --}}
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

    {{-- Filtros aplicados (opcional) --}}
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

    {{-- Contador de registros --}}
    <p class="counter">
        {{ trans_choice('global.records_in_report', $totalCount, ['count' => $totalCount]) }}
    </p>

    {{-- Tabla de datos --}}
    @php
        $headings = [
            'id'             => __('automations.id'),
            'name'           => __('automations.name'),
            'description'    => __('automations.description'),
            'is_active'      => __('automations.is_active'),
            'trigger'        => __('automations.col_trigger'),
            'data_source'    => __('automations.data_source'),
            'action_type'    => __('automations.action_type'),
            'runs_count'     => __('automations.col_runs'),
            'failures_count' => __('automations.col_failures'),
            'last_run_at'    => __('automations.col_last_run'),
            'next_run_at'    => __('automations.col_next_run'),
            'created_at'     => __('global.created_at'),
            'updated_at'     => __('global.updated_at'),
            'creator'        => __('global.created_by'),
        ];

        $formatTrigger = function ($automation) {
            $config = $automation->trigger_config ?? [];
            $kind   = $config['kind'] ?? null;

            return match ($kind) {
                'cron'    => 'Cron: ' . ($config['expression'] ?? '?'),
                'daily'   => __('automations.trigger_kind_daily') . ' ' . ($config['time'] ?? '?'),
                'weekly'  => __('automations.trigger_kind_weekly') . ' ' . __('automations.trigger_day_of_week') . ' ' . ((int) ($config['day'] ?? 1)) . ' ' . ($config['time'] ?? '?'),
                'monthly' => __('automations.trigger_kind_monthly') . ' ' . __('automations.trigger_day_of_month') . ' ' . ($config['day'] ?? '?') . ' ' . ($config['time'] ?? '?'),
                default   => (string) ($automation->trigger_type ?? '—'),
            };
        };
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
                @foreach ($automations as $automation)
                    <tr>
                        @foreach ($columns as $col)
                            <td>
                                @switch($col)
                                    @case('id')          {{ $automation->id }} @break
                                    @case('name')        {{ $automation->name }} @break
                                    @case('description') {{ \Illuminate\Support\Str::limit((string) ($automation->description ?? ''), 200) }} @break
                                    @case('is_active')
                                        <span class="{{ $automation->is_active ? 'status-active' : 'status-inactive' }}">
                                            {{ $automation->is_active ? __('global.active') : __('global.inactive') }}
                                        </span>
                                    @break
                                    @case('trigger')        {{ $formatTrigger($automation) }} @break
                                    @case('data_source')    {{ $automation->data_source ?? '—' }} @break
                                    @case('action_type')    {{ $automation->action_type ?? '—' }} @break
                                    @case('runs_count')     {{ (int) $automation->runs_count }} @break
                                    @case('failures_count') {{ (int) $automation->failures_count }} @break
                                    @case('last_run_at')    {{ $automation->last_run_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) ?? '—' }} @break
                                    @case('next_run_at')    {{ $automation->next_run_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) ?? '—' }} @break
                                    @case('created_at')     {{ $automation->created_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }} @break
                                    @case('updated_at')     {{ $automation->updated_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }} @break
                                    @case('creator')        {{ $automation->creator->name ?? '—' }} @break
                                    @default {{ $automation->{$col} ?? '' }}
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
