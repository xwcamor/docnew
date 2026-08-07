<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardManagement\DashboardController;

/*
|--------------------------------------------------------------------------
| Dashboards — landing post-login
|--------------------------------------------------------------------------
| Cualquier user autenticado (super / admin / user / custom) entra al
| dashboard porque es la pantalla destino de `/` después de login. No usa
| permission middleware a propósito — un user nuevo con rol vacío igual ve
| esto, sin loop de "no tienes permiso" hacia el landing.
|
| Cuando se armen widgets reales con data por rol, hacer el gating ADENTRO
| del controller (qué cards muestra), no en la ruta.
*/
Route::prefix('dashboard_management')->name('dashboard_management.')->group(function () {
    Route::resource('dashboards', DashboardController::class)->names('dashboards');
});