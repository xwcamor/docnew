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

// Purga semanal del caché de gráficos de informes huérfanos (trafos borrados
// definitivamente). El borrado normal ya limpia en el momento; esto barre
// restos. Bajo costo (solo I/O), domingo 03:30.
Schedule::command('reports:purge-chart-cache')
    ->weeklyOn(0, '03:30')
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

// Purga por retención de los ARCHIVOS de informes congelados (diario: el
// costo es una query + unlinks). Conserva snapshot + hash (auditoría).
Schedule::command('reports:purge-frozen')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/purge.log'));

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
