<?php

namespace App\Notifications;

use App\Models\ReportRequest;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a un firmante: tiene una SOLICITUD de informes para revisar y firmar
 * (puede agrupar 1 trafo o una flota).
 *
 * Canal in-app (campana, categoría 'approval', sin leer → prende el badge)
 * SIEMPRE. Por correo SOLO si el workspace lo activó
 * (tenants.notify_approval_by_email) Y el correo está habilitado a nivel
 * plataforma (notifications.email_enabled). Así el admin elige "solo app" o
 * "app + correo" desde Mi workspace.
 */
class ReportApprovalRequested extends Notification
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
        return [
            'title'             => __('approvals.notif_title'),
            'body'              => __('approvals.notif_body', ['ref' => $this->ref()]),
            'category'          => 'approval',
            'report_request_id' => $this->request->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('approvals.notif_title'))
            ->greeting(__('global.greeting_hi', ['name' => $notifiable->name ?? '']))
            ->line(__('approvals.notif_body', ['ref' => $this->ref()]))
            ->action(__('approvals.review'), url('/approvals'));
    }

    /** Referencia legible: la etiqueta de la solicitud o el conteo de informes. */
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
