@extends('share.layout')
@section('title', __('transformers.report.verify_title'))
@section('content')
@php
    $nv = $log?->new_values ?? [];
    $curLang   = app()->getLocale();
    $codeParam = $code ?: request('code');
@endphp

{{-- Selector de idioma (es/en) — la URL no lleva prefijo de locale (es estable
     para el QR impreso), asi que el cliente cambia el idioma a mano aqui. --}}
<div style="text-align:right; margin:0 0 10px;">
    <a href="{{ route('report.verify', array_filter(['lang' => 'es', 'code' => $codeParam])) }}"
       style="font-size:12px; text-decoration:none; padding:3px 10px; border-radius:6px; {{ $curLang === 'es' ? 'background:#354A5F;color:#fff;font-weight:700;' : 'color:#6A6D70;' }}">ES</a>
    <a href="{{ route('report.verify', array_filter(['lang' => 'en', 'code' => $codeParam])) }}"
       style="font-size:12px; text-decoration:none; padding:3px 10px; border-radius:6px; {{ $curLang === 'en' ? 'background:#354A5F;color:#fff;font-weight:700;' : 'color:#6A6D70;' }}">EN</a>
</div>

<div class="card" style="text-align:center;">
    @if ($found)
        <div style="font-size:44px; line-height:1; margin-bottom:10px; color:#1D7044;">&#10003;</div>
        <h1 class="h1">{{ __('transformers.report.verify_ok') }}</h1>
        <p class="sub">{{ __('transformers.report.verify_ok_sub') }}</p>

        <table class="list" style="text-align:left; margin-top:10px;">
            <tr><th>{{ __('transformers.report.verify_code') }}</th><td><code>{{ $code }}</code></td></tr>
            <tr><th>{{ __('transformers.report.code') }}</th><td>{{ $nv['report_code'] ?? '—' }}</td></tr>
            <tr><th>{{ __('transformers.report.verify_issued_at') }}</th><td>{{ $log->created_at->format('d-m-Y H:i') }}</td></tr>
            <tr><th>{{ __('transformers.report.verify_issued_by') }}</th><td>{{ $transformer?->tenant?->name ?? '—' }}</td></tr>
            <tr><th>{{ __('transformers.serial') }}</th><td>{{ $transformer?->serial ?: ($transformer?->tag ?? '—') }}</td></tr>
            <tr><th>{{ __('transformers.report.health_index') }}</th><td>{{ isset($nv['health_index']) && $nv['health_index'] !== null ? $nv['health_index'] . ' %' : '—' }}</td></tr>
            {{-- Firmantes REALES del informe (cargo + nombre), tal como salieron. --}}
            @forelse ($nv['signers'] ?? [] as $s)
                <tr><th>{{ $s['title'] ?? '—' }}</th><td>{{ $s['name'] ?: '—' }}@if (!empty($s['auto_signed'])) <span style="color:#1D7044;">&#10003;</span>@endif</td></tr>
            @empty
                @if (!empty($nv['approver']))<tr><th>{{ __('transformers.report.verify_signers') }}</th><td>{{ $nv['approver'] }}</td></tr>@endif
            @endforelse
        </table>
        <p class="sub" style="margin-top:16px;">{{ __('transformers.report.verify_match_hint') }}</p>
    @elseif ($queried)
        <div style="font-size:44px; line-height:1; margin-bottom:10px; color:#C8281D;">&#10007;</div>
        <h1 class="h1">{{ __('transformers.report.verify_fail') }}</h1>
        <p class="sub">{{ __('transformers.report.verify_fail_sub', ['code' => $code ?? '']) }}</p>
    @else
        <h1 class="h1">{{ __('transformers.report.verify_title') }}</h1>
        <p class="sub">{{ __('transformers.report.verify_form_hint') }}</p>
    @endif

    {{-- Buscador manual: para quien tiene el informe impreso y tipea el código. --}}
    <form method="GET" action="{{ route('report.verify') }}" style="margin-top:18px;">
        <div class="field" style="max-width:320px; margin:0 auto 14px;">
            <input type="text" name="code" class="input" maxlength="14" style="letter-spacing:2px;"
                   placeholder="XXXX-XXXX-XXXX" value="" autocomplete="off">
        </div>
        <button type="submit" class="btn">{{ __('transformers.report.verify_form_btn') }}</button>
    </form>
</div>
<p class="foot">{{ __('transformers.report.verify_foot') }}</p>
@endsection
