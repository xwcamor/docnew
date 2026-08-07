@extends('layouts.information')

@section('title', __('terms.title'))

@section('content')
<div class="container py-4">
  <h1 class="mb-4">{{ config('app.name') }}</h1> 
  <h1 class="mb-4">{{ __('terms.title') }}</h1>
  <hr>
  @foreach(__('terms.sections') as $section)
    <h5 class="mt-3"><b>{{ __($section['title']) }}</b></h5>
    <p style="white-space: pre-line;">
      {{ __($section['text'], [
          'app'   => config('app.name'),
          'email' => config('mail.from.address'),
          'year'  => date('Y')
      ]) }}
    </p>
  @endforeach

  <hr class="my-4">

  <p class="text-center text-muted small" style="white-space: pre-line;">
    {!! __('terms.footer', [
        'year'  => date('Y'),
        'app'   => config('app.name'),
        'email' => config('mail.from.address')
    ]) !!}
  </p>

</div>
@endsection