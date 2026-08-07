<?php

namespace App\Mail;

use App\Models\ReportShare;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Mail 1: invitación con el enlace para ver el reporte compartido. */
class ShareInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ReportShare $share,
        public string $url,
        public ?string $logo = null,
        public ?string $sharedBy = null,
    ) {
    }

    public function build(): self
    {
        return $this->subject(__('sharing.mail_invite_subject'))
            ->view('emails.share.invite');
    }
}
