<?php

namespace App\Notifications;

use App\Models\ReportRequest;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al SOLICITANTE: su solicitud de informe(s) fue aprobada y emitida.
 * Si nació de un "compartir", el enlace ya salió al cliente automáticamente.
 *
 * Canal in-app (campana, categoría 'approval') SIEMPRE. Por correo solo si el
 * workspace activó notify_approval_by_email Y el correo está habilitado a nivel
 * plataforma — mismo criterio que ReportApprovalRequested.
 */
class ReportRequestApproved extends Notification
{
    use Queueable;

    public function __construct(public ReportRequest $request) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $byEmail = (bool) ($this->request->tenant?->notify_approval_by_email ?? false);
        if ($byEmail && Setting::getBool('notifications.email_enabled', true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        $shared = (bool) $this->request->report_share_id;

        return [
            'title'             => __('approvals.requester_approved_title'),
            'body'              => __($shared ? 'approvals.requester_approved_shared_body' : 'approvals.requester_approved_body', ['ref' => $this->ref()]),
            'category'          => 'approval',
            'report_request_id' => $this->request->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shared = (bool) $this->request->report_share_id;

        return (new MailMessage)
            ->subject(__('approvals.requester_approved_title'))
            ->greeting(__('global.greeting_hi', ['name' => $notifiable->name ?? '']))
            ->line(__($shared ? 'approvals.requester_approved_shared_body' : 'approvals.requester_approved_body', ['ref' => $this->ref()]))
            ->action(__('approvals.my_requests_menu'), url('/my-requests'));
    }

    /** Referencia legible: etiqueta de la solicitud o conteo de informes. */
    private function ref(): string
    {
        if ($this->request->label) {
            return $this->request->label;
        }
        $count = $this->request->instances()->count();
        if ($count === 1) {
            $t = $this->request->instances()->first()?->transformer;
            return $t?->serial ?: ($t?->tag ?: __('approvals.one_report'));
        }
        return trans_choice('approvals.n_reports', $count, ['count' => $count]);
    }
}
