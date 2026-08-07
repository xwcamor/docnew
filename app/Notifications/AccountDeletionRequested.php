<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Un usuario ejerció su derecho de cancelación (LPDP): pidió la eliminación
 * de su cuenta/datos desde su perfil. Va a los ADMINS del workspace (y super
 * como fallback) — la eliminación la ejecuta un admin desde el módulo Users,
 * para mantener trazabilidad y no romper integridad (informes emitidos, audit).
 */
class AccountDeletionRequested extends Notification
{
    use Queueable;

    public function __construct(
        public string $requesterName,
        public string $requesterEmail,
    ) {}

    public function via(object $notifiable): array
    {
        return \App\Models\Setting::getBool('notifications.email_enabled', true)
            ? ['mail', 'database']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name', 'TR Health');

        return (new MailMessage)
            ->subject(__('mail.deletion_request_subject', ['app' => $appName]))
            ->greeting(__('mail.deletion_request_greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('mail.deletion_request_body', ['name' => $this->requesterName, 'email' => $this->requesterEmail]))
            ->line(__('mail.deletion_request_hint'))
            ->action(__('mail.deletion_request_button'), url(route('user_management.users.index', [], false)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'    => __('mail.deletion_request_subject', ['app' => config('app.name', 'TR Health')]),
            'body'     => __('mail.deletion_request_body', ['name' => $this->requesterName, 'email' => $this->requesterEmail]),
            'category' => 'security',
        ];
    }
}
