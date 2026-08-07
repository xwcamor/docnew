<?php

namespace App\Notifications;

use App\Models\Download;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * DownloadFailed — se dispara cuando un Download pasa a status='failed'.
 *
 * SOLO in-app y SIN correo. La campana del header ya muestra el fallo (con su
 * error_message) desde la query directa de la tabla `downloads`
 * (HandleInertiaRequests::buildInboxPayload).
 *
 * via() devuelve [] → ningún canal. Sin toMail() a propósito: imposible enviar
 * correo aunque alguien agregue un canal por error.
 */
class DownloadFailed extends Notification
{
    use Queueable;

    public function __construct(public Download $download) {}

    public function via(object $notifiable): array
    {
        return [];
    }
}
