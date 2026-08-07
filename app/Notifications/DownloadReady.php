<?php

namespace App\Notifications;

use App\Models\Download;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * DownloadReady — se dispara cuando un Download pasa a status='ready'.
 *
 * SOLO in-app y SIN correo. La campana del header ya muestra la descarga lista
 * leyendo directo de la tabla `downloads` (ver HandleInertiaRequests::
 * buildInboxPayload), así que el aviso vive ahí; un correo sería redundante.
 *
 * via() devuelve [] → la notificación no usa NINGÚN canal. No hay método
 * toMail() a propósito: así es imposible que se envíe correo aunque alguien
 * agregue un canal por error.
 */
class DownloadReady extends Notification
{
    use Queueable;

    public function __construct(public Download $download) {}

    public function via(object $notifiable): array
    {
        return [];
    }
}
