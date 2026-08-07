<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('subscriptions.expired_warning') }}</title>
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
            max-width: 560px;
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
            background: #FEE2E2;
            color: #B91C1C;
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
        .tenant-name {
            display: inline-block;
            padding: 4px 10px;
            background: #F1F5F9;
            border-radius: 4px;
            font-weight: 500;
            margin-bottom: 16px;
        }
        p {
            color: #6A6D70;
            line-height: 1.55;
            margin-bottom: 16px;
        }
        a {
            color: #0A6ED1;
            text-decoration: none;
            font-weight: 500;
        }
        a:hover { text-decoration: underline; }
        .actions {
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid #E5E5E5;
        }
        .logout-btn {
            display: inline-block;
            padding: 8px 20px;
            background: white;
            color: #6A6D70;
            border: 1px solid #E5E5E5;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.875rem;
        }
        .logout-btn:hover { background: #f1f5f9; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="icon">⏱</div>
            <div class="tenant-name">{{ $tenantName }}</div>
            <h1>{{ __('subscriptions.expired_warning') }}</h1>
            <p>{{ __('subscriptions.no_active_hint') }}</p>
            @if ($supportEmail)
                <p>
                    {{ __('global.maintenance_contact') }}
                    <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                </p>
            @endif

            <div class="actions">
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button type="submit" class="logout-btn">{{ __('global.logout') }}</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
