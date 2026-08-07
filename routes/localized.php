<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

Route::group(
    [
        'prefix' => LaravelLocalization::setLocale(),
        'middleware' => ['localeSessionRedirect','localizationRedirect','localeViewPath'],
    ],
    function () {
        // Página principal protegida
        Route::middleware(['auth'])->group(function () {
            Route::get('/', function () {
                return Auth::check()
                    ? redirect()->route('auth_management.users.index')
                    : redirect()->route('login');
            });
        });

        // Rutas legales (públicas)
        Route::view('/terms', 'legal.terms')->name('terms');
        Route::view('/privacy', 'legal.privacy')->name('privacy');

        // Incluir módulos
        require __DIR__.'/auth_management.php';
        require __DIR__.'/system_management.php';
        require __DIR__.'/notifications.php';
    }
);
