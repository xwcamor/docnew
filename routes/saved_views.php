<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SavedViewController;

/*
|--------------------------------------------------------------------------
| Saved Views — bandeja per-user de vistas guardadas (multi-módulo)
|--------------------------------------------------------------------------
| Reusable: cualquier listado puede aprovechar este endpoint pasando
| ?module=<nombre>. No requiere ningún permiso especial — son datos
| privados del usuario.
*/
Route::prefix('saved-views')->middleware('plan_feature:saved_views')->name('saved_views.')->group(function () {
    Route::get('/',          [SavedViewController::class, 'index'])->name('index');
    Route::post('/',         [SavedViewController::class, 'store'])->name('store');
    Route::put('/{id}',      [SavedViewController::class, 'update'])->name('update');
    Route::delete('/{id}',   [SavedViewController::class, 'destroy'])->name('destroy');
});
