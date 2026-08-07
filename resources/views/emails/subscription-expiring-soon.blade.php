<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('subscriptions.email_subject', ['days' => $daysRemaining]) }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f8fafc; margin: 0; padding: 24px; color: #32363A; }
        .wrap { max-width: 560px; margin: 0 auto; background: white; border-radius: 8px; padding: 32px; box-shadow: 0 2px 8px rgba(15,23,42,0.06); }
        .header { border-bottom: 1px solid #E5E5E5; padding-bottom: 16px; margin-bottom: 20px; }
        h1 { font-size: 1.25rem; margin: 0; color: #B45309; }
        p { line-height: 1.55; margin: 0 0 14px; color: #4B5563; }
        .info-box { background: #FEF3C7; border-left: 4px solid #F59E0B; padding: 12px 16px; border-radius: 4px; margin: 18px 0; }
        .info-box dl { margin: 0; }
        .info-box dt { font-weight: 600; color: #92400E; margin-top: 8px; }
        .info-box dd { margin: 2px 0 0 0; color: #4B5563; }
        .info-box dt:first-child { margin-top: 0; }
        .footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid #E5E5E5; font-size: 0.875rem; color: #6B7280; }
        a { color: #0A6ED1; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <h1>{{ __('subscriptions.email_title', ['days' => $daysRemaining]) }}</h1>
        </div>

        <p>{{ __('subscriptions.email_greeting', ['name' => $adminName]) }}</p>

        <p>{{ __('subscriptions.email_body', ['workspace' => $tenantName, 'days' => $daysRemaining]) }}</p>

        <div class="info-box">
            <dl>
                <dt>{{ __('subscriptions.plan') }}</dt>
                <dd>{{ strtoupper($plan) }}</dd>
                <dt>{{ __('subscriptions.ends_at') }}</dt>
                <dd>{{ $endsAt?->format('Y-m-d') }}</dd>
                <dt>{{ __('subscriptions.days_remaining') }}</dt>
                <dd>{{ $daysRemaining }}</dd>
            </dl>
        </div>

        <p>{{ __('subscriptions.email_cta') }}</p>

        @if ($supportEmail)
            <div class="footer">
                {{ __('subscriptions.email_support_hint') }}
                <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
            </div>
        @endif
    </div>
</body>
</html>
