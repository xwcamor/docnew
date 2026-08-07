<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserPreferences\ModuleTourController;
use App\Http\Controllers\UserPreferences\FavoriteController;
use App\Http\Controllers\UserPreferences\RecentViewController;

/*
|--------------------------------------------------------------------------
| User Preferences — endpoints per-user que no son CRUD de un recurso
|--------------------------------------------------------------------------
| Reusables entre módulos. No requieren permisos especiales (son datos
| privados del propio usuario).
*/
Route::prefix('user-prefs')->name('user_prefs.')->group(function () {
    // Onboarding tours: el frontend marca como completado cuando el usuario
    // termina o cierra el tour de un módulo, así no se le vuelve a mostrar.
    Route::post('module-tours/complete', [ModuleTourController::class, 'complete'])
        ->name('module_tours.complete');
    Route::delete('module-tours/complete', [ModuleTourController::class, 'reset'])
        ->name('module_tours.reset');

    // Favoritos polimórficos: toggle on/off por (módulo, id).
    Route::post('favorites/toggle', [FavoriteController::class, 'toggle'])
        ->name('favorites.toggle');

    // Recent views: trackear vista de cualquier registro (drawer open,
    // hover preview, etc.) sin tener que ir a la página Show.
    Route::post('recent-views/track', [RecentViewController::class, 'track'])
        ->name('recent_views.track');
});
