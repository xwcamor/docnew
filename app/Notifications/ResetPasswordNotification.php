<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notification de "olvidé contraseña" — override del default de Laravel para
 * usar traducciones del proyecto (es/en/pt). El default usa strings hardcoded
 * en ingles via Lang::get('Reset Password Notification') etc, que solo se
 * traducen si publicas las plantillas exactas de Laravel.
 *
 * El locale efectivo lo pone Laravel:
 *   - Si el notifiable (User) implementa HasLocalePreference → preferredLocale()
 *   - Si no → locale actual del request (`app()->getLocale()`)
 */
class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);
        $expireMinutes = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60);
        $appName = config('app.name', 'Application');

        return (new MailMessage)
            ->subject(__('auth.password_reset_subject', ['app' => $appName]))
            ->greeting(__('auth.password_reset_greeting'))
            ->line(__('auth.password_reset_line_intro'))
            ->action(__('auth.password_reset_button'), $url)
            ->line(__('auth.password_reset_line_expire', ['count' => $expireMinutes]))
            ->line(__('auth.password_reset_line_ignore'))
            ->salutation(__('auth.password_reset_salutation', ['app' => $appName]));
    }

    /**
     * Reusa la URL builder del padre (callbacks `createUrlUsing`) si el host
     * registro una. Si no, replica el comportamiento default.
     */
    protected function resetUrl($notifiable): string
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable, $this->token);
        }

        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
