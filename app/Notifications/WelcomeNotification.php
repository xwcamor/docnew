<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email para usuarios recien creados. Trae las credenciales iniciales
 * (email + password en plano) para que el user pueda entrar la primera vez,
 * mas un CTA explicito a cambiar la clave desde su perfil.
 *
 * Solo canal `mail` — al ser cuenta recien creada no hay sesion previa donde
 * el bell del header tenga utilidad. Respeta el toggle global
 * `notifications.email_enabled` (si esta off, no envia nada).
 */
class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $plainPassword,
    ) {}

    public function via(object $notifiable): array
    {
        return \App\Models\Setting::getBool('notifications.email_enabled', true)
            ? ['mail']
            : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName  = config('app.name', 'Application');
        $loginUrl = url(route('login', [], false));

        return (new MailMessage)
            ->subject(__('mail.welcome_subject', ['app' => $appName]))
            ->greeting(__('mail.welcome_greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('mail.welcome_intro', ['app' => $appName]))
            ->line(__('mail.welcome_credentials_intro'))
            ->line('**' . __('mail.welcome_email_label') . ':** ' . $notifiable->email)
            ->line('**' . __('mail.welcome_password_label') . ':** ' . $this->plainPassword)
            ->action(__('mail.welcome_button'), $loginUrl)
            ->line(__('mail.welcome_change_password_hint'))
            ->salutation(__('mail.welcome_salutation', ['app' => $appName]));
    }
}
