<?php

namespace App\Mail;

use App\Models\ReportShare;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Mail 2: código de acceso (OTP) para abrir el reporte. */
class ShareOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ReportShare $share,
        public string $code,
        public ?string $logo = null,
    ) {
    }

    public function build(): self
    {
        return $this->subject(__('sharing.mail_otp_subject'))
            ->view('emails.share.otp');
    }
}
