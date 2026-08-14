<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purga diaria de soft-deleted antiguos según config/purge.php.
// Corre a las 03:00 (hora baja de tráfico) y se loguea para inspección.
Schedule::command('app:purge-soft-deleted')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/purge.log'));

// Purga del historial de cambios (`audit_logs`): poda el contenido de las filas
// viejas y borra las que ya pasaron su plazo. La política, con su porqué, está
// en config/purge.php → bloque `audit_logs`.
//
// A las 03:30 y no a las 03:00: la purga de soft-deleted de arriba escribe su
// propio resumen en el historial, y lanzar las dos a la vez es pelearse por la
// misma tabla de madrugada sin ganar nada. `withoutOverlapping` no serviría
// aquí — son comandos distintos, cada uno con su candado.
Schedule::command('app:purge-audit-logs')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/purge.log'));

// Limpieza de archivos físicos de exports expirados o descargados (>24h).
// Corre cada hora — el costo es bajo (solo I/O del disco) y mantiene
// `storage/app/downloads/` chico sin acumular MBs de reportes viejos.
Schedule::command('app:cleanup-expired-downloads')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/cleanup-downloads.log'));

// Purga las claves de idempotencia de la API vencidas (30 días). Es registro
// técnico de la integración con el laboratorio, no dato de negocio: pasada esa
// ventana nadie va a reintentar ese envío. Diario, de madrugada.
Schedule::command('api:purge-idempotency-keys')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/purge.log'));

// Purga notificaciones de automation con mas de 12 horas. Las notifs de
// automation son info ambient (no requieren ack), se autoborran para que
// el bell no se llene. Otras categorias (security, plan_change) no se tocan.
Schedule::command('automations:purge-old-notifications')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
