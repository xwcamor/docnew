@extends('share.layout')
@section('title', $summary['serial'] ?: $summary['tag'])
@section('content')
    @isset($backUrl)
        <p style="margin:0 0 12px;"><a href="{{ $backUrl }}" class="btn--ghost btn" style="padding:6px 14px; font-size:13px;">&larr; {{ __('sharing.back_to_fleet') }}</a></p>
    @endisset

    @include('share._diagcard', ['summary' => $summary, 'pdfUrl' => route('share.pdf', ['token' => $share->token, 'transformer' => $summary['id']])])

    {{-- Detalle completo: narrativa + tablas con límites + conclusiones. --}}
    @include('share._fulldetail', ['detail' => $detail])
@endsection
