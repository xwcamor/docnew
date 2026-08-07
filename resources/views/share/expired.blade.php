@extends('share.layout')
@section('title', __('sharing.expired_title'))
@section('content')
<div class="card" style="text-align:center;">
    <h1 class="h1">{{ __('sharing.expired_title') }}</h1>
    <p class="sub" style="margin:0;">{{ __('sharing.expired_body') }}</p>
</div>
@endsection
