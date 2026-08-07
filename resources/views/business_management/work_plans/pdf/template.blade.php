<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* DomPDF: solo Helvetica, font-weight normal/bold. Clon del master. */
        @page { margin: 24px 28px 24px 28px; }
        body { font-family: Helvetica; font-size: 9pt; color: #32363A; margin: 0; }
        .brand-band { background: #354A5F; color: #ffffff; padding: 14px 18px; margin-bottom: 14px; }
        .brand-band__meta { float: right; font-size: 8pt; color: #cbd5e1; text-align: right; line-height: 1.4; }
        .brand-band__meta strong { color: #ffffff; font-weight: bold; }
        .brand-band__title { font-size: 14pt; font-weight: bold; margin: 0; letter-spacing: 0.01em; }
        .brand-band__sub { font-size: 8pt; color: #cbd5e1; margin: 4px 0 0 0; }
        .filters { background: #F0F6FB; border-left: 3px solid #0A6ED1; padding: 8px 12px; margin: 0 0 12px 0; font-size: 8.5pt; color: #334155; }
        .filters__title { display: block; font-weight: bold; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #0A6ED1; margin: 0 0 4px 0; }
        .filters__list { margin: 0; padding: 0; list-style: none; }
        .filters__list li { line-height: 1.5; }
        .filters__list li b { font-weight: bold; color: #1f2937; }
        .counter { font-size: 8.5pt; color: #6A6D70; margin: 0 0 8px 0; }
        table.data { width: 100%; border-collapse: collapse; margin: 0; }
        table.data thead th { background: #0A6ED1; color: #ffffff; font-weight: bold; font-size: 9pt; text-align: left; padding: 6px 8px; border: 1px solid #085CAF; }
        table.data tbody td { padding: 5px 8px; border: 1px solid #E5E5E5; font-size: 8.5pt; color: #32363A; }
        table.data tbody tr:nth-child(even) td { background: #F8FAFC; }
        .status-active   { color: #1D7044; font-weight: bold; }
        .status-inactive { color: #C8281D; font-weight: bold; }
        .empty { text-align: center; padding: 32px 20px; color: #6A6D70; font-size: 9pt; }
        .doc-footer { margin-top: 16px; padding-top: 8px; border-top: 1px solid #E5E5E5; font-size: 7.5pt; color: #6A6D70; text-align: center; }
    </style>
</head>
<body>
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

    <p class="counter">
        {{ trans_choice('global.records_in_report', $totalCount, ['count' => $totalCount]) }}
    </p>

    @php
        $headings = [
            'id'            => __('work_plans.id'),
            'code'          => __('work_plans.code'),
            'num_os'        => __('work_plans.num_os'),
            'description'   => __('work_plans.description'),
            'company'       => __('work_plans.company'),
            'work_type'     => __('work_plans.work_type'),
            'work_location' => __('work_plans.work_location'),
            'workstation'   => __('work_plans.workstation'),
            'work_area'     => __('work_plans.work_area'),
            'date_start'    => __('work_plans.date_start'),
            'date_end'      => __('work_plans.date_end'),
            'is_done'       => __('work_plans.is_done'),
            'is_locked'     => __('work_plans.is_locked'),
            'people_count'  => __('work_plans.people_count'),
            'registered_by' => __('work_plans.registered_by'),
            'slug'       => 'Slug',
            'created_at' => __('global.created_at'),
            'updated_at' => __('global.updated_at'),
            'creator'    => __('global.created_by'),
            'tenant'     => __('tenants.singular'),
        ];
    @endphp

    @if ($totalCount === 0)
        <div class="empty">{{ __('global.no_matching_records') }}</div>
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
                @foreach ($work_plans as $item)
                    <tr>
                        @foreach ($columns as $col)
                            <td>
                                @switch($col)
                                    @case('id')         {{ $item->id }} @break
                                    @case('code')       {{ $item->code }} @break
                                    @case('num_os')     {{ $item->num_os }} @break
                                    @case('description'){{ $item->description }} @break
                                    @case('company')       {{ $item->company?->name ?? '—' }} @break
                                    @case('work_type')     {{ $item->workType?->code ?? '—' }} @break
                                    @case('work_location') {{ $item->workLocation?->name ?? '—' }} @break
                                    @case('workstation')   {{ $item->workstation?->name ?? '—' }} @break
                                    @case('work_area')     {{ $item->workArea?->name ?? '—' }} @break
                                    @case('date_start')    {{ $item->date_start?->format('Y-m-d') }} @break
                                    @case('date_end')      {{ $item->date_end?->format('Y-m-d') }} @break
                                    @case('people_count')  {{ $item->people_count ?? 0 }} @break
                                    @case('registered_by') {{ $item->user?->name ?? '—' }} @break
                                    @case('is_locked')     {{ $item->is_locked ? __('global.yes') : __('global.no') }} @break
                                    @case('is_done')
                                        {{-- Verde si el plan esta terminado, rojo si sigue pendiente. --}}
                                        <span class="{{ $item->is_done ? 'status-active' : 'status-inactive' }}">
                                            {{ $item->state_text }}
                                        </span>
                                    @break
                                    @case('slug')       {{ $item->slug }} @break
                                    @case('created_at') {{ $item->created_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }} @break
                                    @case('updated_at') {{ $item->updated_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT) }} @break
                                    @case('creator')    {{ $item->creator->name ?? '—' }} @break
                                    @case('tenant')     {{ $item->tenant?->name ?? '—' }} @break
                                    @default {{ $item->{$col} ?? '' }}
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