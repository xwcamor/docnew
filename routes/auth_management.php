<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthManagement\Auth\LoginController;
use App\Http\Controllers\AuthManagement\Auth\GoogleLoginController;
use App\Http\Controllers\AuthManagement\Auth\ForgotPasswordController;
use App\Http\Controllers\AuthManagement\Auth\ResetPasswordController;

// ------------------------------
// Login & Logout
// ------------------------------
Route::get('login',   [LoginController::class, 'login'])->name('login');
Route::post('login',  [LoginController::class, 'loginAccess'])->name('login.post');
Route::post('logout', [LoginController::class, 'logout'])->name('logout');

// ------------------------------
// Google Login
// ------------------------------
Route::prefix('auth_management')->name('auth_management.')->group(function () {
    Route::controller(GoogleLoginController::class)->group(function () {
        Route::get('google/redirect', 'redirectToGoogle')->name('google.redirect');
        Route::get('google/callback', 'handleGoogleCallback')->name('google.callback');
    });
});

// ------------------------------
// Forgot & Reset Password
// ------------------------------
// Los POST llevan throttle agresivo para mitigar enumeración de emails y
// abuso del servicio de mail. 5 intentos / minuto por IP es el patrón
// estándar de Laravel para password reset.
Route::middleware('guest')->group(function () {
    Route::get('password/reset', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('password/email', [ForgotPasswordController::class, 'sendResetLinkEmail'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('password/reset/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('password/reset', [ResetPasswordController::class, 'reset'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});