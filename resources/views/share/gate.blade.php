@extends('share.layout')
@section('title', __('sharing.gate_title'))
@section('content')
@php
    // Email enmascarado: j***@dominio.com
    $parts = explode('@', $share->recipient_email);
    $masked = mb_substr($parts[0], 0, 1) . '***@' . ($parts[1] ?? '');
@endphp
<div class="card">
    <h1 class="h1">{{ __('sharing.gate_title') }}</h1>
    <p class="sub">{{ __('sharing.gate_intro', ['email' => $masked]) }}</p>

    @if (session('codeSent'))
        <div class="alert alert--ok">{{ __('sharing.gate_code_sent') }}</div>
    @endif
    @error('code')
        <div class="alert alert--err">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('share.code', $share->token) }}" style="margin-bottom:18px;">
        @csrf
        <button type="submit" class="btn--ghost btn">{{ session('codeSent') ? __('sharing.gate_resend') : __('sharing.gate_send') }}</button>
    </form>

    <form method="POST" action="{{ route('share.verify', $share->token) }}">
        @csrf
        <div class="field">
            <input type="text" name="code" class="input" inputmode="numeric" autocomplete="one-time-code"
                   maxlength="6" placeholder="••••••" required autofocus>
        </div>
        <button type="submit" class="btn">{{ __('sharing.gate_verify') }}</button>
    </form>

    {{-- Aviso de privacidad (LPDP): el destinatario es un tercero cuyo email
         se usa solo para verificar identidad. Transparencia + link a la política. --}}
    <p class="muted" style="font-size:12px; margin:18px 0 0; line-height:1.5;">
        {{ __('sharing.gate_privacy_note') }}
        <a href="{{ route('legal_management.privacy') }}" target="_blank" rel="noopener">{{ __('sharing.gate_privacy_link') }}</a>
    </p>
</div>
@endsection
