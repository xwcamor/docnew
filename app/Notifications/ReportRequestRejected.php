<?php

namespace App\Notifications;

use App\Models\ReportRequest;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al SOLICITANTE: un firmante RECHAZÓ su solicitud (con motivo). La
 * solicitud vuelve al preparador, que puede corregir y reenviar desde
 * "Mis solicitudes".
 *
 * Mismos canales que ReportRequestApproved (database siempre; mail opt-in).
 */
class ReportRequestRejected extends Notification
{
    use Queueable;

    public function __construct(public ReportRequest $request, public string $reason) {}

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
        return [
            'title'             => __('approvals.requester_rejected_title'),
            'body'              => __('approvals.requester_rejected_body', ['ref' => $this->ref()]),
            'reason'            => $this->reason,
            'category'          => 'approval',
            'report_request_id' => $this->request->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('approvals.requester_rejected_title'))
            ->greeting(__('global.greeting_hi', ['name' => $notifiable->name ?? '']))
            ->line(__('approvals.requester_rejected_body', ['ref' => $this->ref()]))
            ->line(__('approvals.reject_reason_label') . ': ' . $this->reason)
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
