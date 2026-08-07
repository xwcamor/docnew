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
                <tr><td style="padding:28px;">
                    <h1 style="font-size:18px; margin:0 0 12px;">{{ __('sharing.mail_otp_title') }}</h1>
                    <p style="font-size:14px; line-height:1.5; margin:0 0 18px;">{{ __('sharing.mail_otp_body') }}</p>
                    <div style="text-align:center; margin:0 0 18px;">
                        <span style="display:inline-block; font-size:30px; font-weight:bold; letter-spacing:8px; color:#0A6ED1; background:#F0F6FB; padding:14px 22px; border-radius:8px;">{{ $code }}</span>
                    </div>
                    <p style="font-size:12px; color:#9aa0a6; margin:0;">{{ __('sharing.mail_otp_expires') }}</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
