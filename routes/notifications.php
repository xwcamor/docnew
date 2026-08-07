<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Notifications\NotificationController;

/*
|--------------------------------------------------------------------------
| Notifications routes
|--------------------------------------------------------------------------
| Hub donde aterriza todo lo "para enterar al usuario": archivos exportados
| listos, tareas pendientes, alertas, etc. Hoy solo serve downloads, pero
| la URL/módulo ya está pensado como bandeja unificada.
*/
Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/',                 [NotificationController::class, 'index'])->name('index');
    Route::get('/{id}/download',    [NotificationController::class, 'download'])->name('download');
    Route::delete('/{id}',          [NotificationController::class, 'delete'])->name('delete');

    // App notifications (tabla `notifications` estándar de Laravel) —
    // independiente del recurso "downloads" del bell legacy. Las usa
    // InAppNotificationAction de Automations y todo lo que dispare
    // $user->notify() con channel database.
    Route::post('/app/{id}/read',   [NotificationController::class, 'markAppRead'])->name('app.read');
    Route::post('/app/read-all',    [NotificationController::class, 'markAllAppRead'])->name('app.read_all');
});
