<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * WelcomeUser — saludo de bienvenida que aparece en la campana del usuario
 * recién creado (solo in-app, sin correo: es informativo / solo lectura).
 *
 * Recuerda cambiar la contraseña inicial y explica cómo. Se renderiza en el
 * idioma del propio usuario (se setea el locale al despachar).
 *
 * Las políticas (Términos/Privacidad) NO van aquí: su aceptación ya es
 * obligatoria en el primer login (modal bloqueante), sería redundante.
 *
 * NO ShouldQueue a propósito: es un solo insert, queremos que esté en la
 * campana desde el primer login sin depender del worker de colas.
 */
class WelcomeUser extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $app = config('app.name', 'TR Health');

        return [
            'title'    => __('users.welcome_title', ['app' => $app]),
            'body'     => __('users.welcome_body'),
            'category' => 'info',
        ];
    }
}
