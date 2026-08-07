<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0; background:#f4f6f8; font-family: Arial, Helvetica, sans-serif; color:#32363A;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding:24px 0;">
        <tr><td align="center">
            <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden; max-width:520px;">
                <tr><td style="background:#354A5F; padding:18px 24px;" align="center">
                    @if ($logo)
                        <img src="{{ $logo }}" alt="" style="max-height:40px; max-width:180px;">
                    @else
                        <span style="color:#ffffff; font-size:16px; font-weight:bold;">{{ config('app.name') }}</span>
                    @endif
                </td></tr>
                <tr><td style="padding:28px 28px 8px;">
                    <h1 style="font-size:18px; margin:0 0 12px;">{{ __('sharing.mail_invite_title') }}</h1>
                    <p style="font-size:14px; line-height:1.5; margin:0 0 8px;">
                        {{ __('sharing.mail_invite_body', ['by' => $sharedBy ?: config('app.name')]) }}
                    </p>
                    <p style="font-size:13px; color:#6A6D70; margin:0 0 20px;">
                        {{ __('sharing.mail_invite_otp_note') }}
                    </p>
                    <p style="margin:0 0 24px;">
                        <a href="{{ $url }}" style="background:#0A6ED1; color:#ffffff; text-decoration:none; padding:11px 22px; border-radius:6px; font-size:14px; font-weight:bold; display:inline-block;">
                            {{ __('sharing.mail_invite_cta') }}
                        </a>
                    </p>
                    <p style="font-size:12px; color:#9aa0a6; margin:0 0 4px;">{{ __('sharing.mail_invite_expires', ['date' => $share->expires_at->format('Y-m-d')]) }}</p>
                </td></tr>
                <tr><td style="padding:12px 28px 24px; border-top:1px solid #eef0f2;">
                    <p style="font-size:11px; color:#9aa0a6; margin:0; word-break:break-all;">{{ $url }}</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
