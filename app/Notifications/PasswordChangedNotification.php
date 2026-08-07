<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica al user que su contrasena fue cambiada. Cubre 3 flujos:
 *   - El user la cambio desde su perfil
 *   - El admin/super la cambio editando el user
 *   - El user uso "olvide mi contrasena" y completo el reset
 *
 * Importante para seguridad: si el cambio NO lo hizo el user, este mensaje le
 * avisa para que tome accion. Por eso el email se manda siempre que cambia la
 * password, sin importar quien la cambio.
 *
 * Canales: mail + database (bell). El bell deja registro persistente que el
 * user vera la proxima vez que entre, incluso si el email cae en spam.
 */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * $context describe quien hizo el cambio:
     *   'self'  → el usuario cambio su propia clave desde perfil
     *   'admin' → un admin/super la cambio editando el user
     *   'reset' → el user completo el flujo de "olvide contrasena"
     */
    public function __construct(
        public string $context = 'self',
    ) {}

    public function via(object $notifiable): array
    {
        return \App\Models\Setting::getBool('notifications.email_enabled', true)
            ? ['mail', 'database']
            : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName    = config('app.name', 'Application');
        $changedAt  = now()->setTimezone(\App\Support\Tz::for($notifiable))
                          ->isoFormat('LLLL');
        $resetUrl   = url(route('password.request', [], false));

        $intro = match ($this->context) {
            'admin' => __('mail.password_changed_intro_admin'),
            'reset' => __('mail.password_changed_intro_reset'),
            default => __('mail.password_changed_intro_self'),
        };

        return (new MailMessage)
            ->subject(__('mail.password_changed_subject', ['app' => $appName]))
            ->greeting(__('mail.password_changed_greeting', ['name' => $notifiable->name ?? '']))
            ->line($intro)
            ->line(__('mail.password_changed_when', ['datetime' => $changedAt]))
            ->line(__('mail.password_changed_security_warning'))
            ->action(__('mail.password_changed_button'), $resetUrl)
            ->salutation(__('mail.password_changed_salutation', ['app' => $appName]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title'    => __('mail.password_changed_subject', ['app' => config('app.name', 'Application')]),
            'body'     => __('mail.password_changed_security_warning'),
            'context'  => $this->context,
            'category' => 'security',
        ];
    }
}
