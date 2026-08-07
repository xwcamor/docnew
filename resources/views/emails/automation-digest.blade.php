<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $bodyText }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #1f2937; max-width: 600px; margin: 0 auto; padding: 24px;">
    <div style="white-space: pre-wrap;">{{ $bodyText }}</div>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 32px 0 16px;">
    <p style="font-size: 12px; color: #6b7280;">
        {{ __('automations.email_footer') }}
    </p>
</body>
</html>
