<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('global.app_name') }} — @yield('title')</title>

    {{-- Bundle Vite (Tailwind v4 + tokens del proyecto). Mismos assets que el SPA. --}}
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[var(--color-surface-alt,#f8fafc)] text-[var(--color-text,#32363A)]">
    <header class="border-b border-gray-200 bg-white">
        <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ url('/') }}" class="text-lg font-semibold text-[var(--color-text-strong,#1e293b)]">
                {{ config('app.name') }}
            </a>
            <a href="{{ route('login') }}" class="text-sm text-[var(--color-primary,#0A6ED1)] hover:underline">
                ← @lang('global.back')
            </a>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-6 py-8">
        <article class="prose prose-slate max-w-none bg-white p-6 rounded-md shadow-sm">
            @yield('content')
        </article>
    </main>

    <footer class="max-w-3xl mx-auto px-6 py-6 text-xs text-gray-500">
        © {{ date('Y') }} {{ config('app.name') }}
    </footer>
</body>
</html>
