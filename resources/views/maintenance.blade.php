<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('global.maintenance_title') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            background: #f8fafc;
            color: #32363A;
        }
        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            max-width: 520px;
            width: 100%;
            background: white;
            border-radius: 8px;
            padding: 48px 40px;
            box-shadow: 0 10px 40px rgba(15, 23, 42, 0.08);
            text-align: center;
        }
        .icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #FEF3C7;
            color: #B45309;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 24px;
        }
        h1 {
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 12px;
        }
        p {
            color: #6A6D70;
            line-height: 1.55;
            margin-bottom: 16px;
        }
        a {
            color: #0A6ED1;
            text-decoration: none;
        }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="icon">⚙</div>
            <h1>{{ __('global.maintenance_title') }}</h1>
            <p>{{ __('global.maintenance_message') }}</p>
            @if ($supportEmail)
                <p>
                    {{ __('global.maintenance_contact') }}
                    <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                </p>
            @endif
        </div>
    </div>
</body>
</html>
