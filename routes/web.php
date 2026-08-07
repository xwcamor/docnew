<?php

// Use Illuminates
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;

// Localization
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

use App\Http\Controllers\AuthManagement\UserController;
use App\Http\Controllers\PublicShareController;

Route::group(
    [
        'prefix' => LaravelLocalization::setLocale(),
        'middleware' => [ 'localeSessionRedirect','localizationRedirect','localeViewPath' ],
    ],
    function () {

        // Protected by auth
        Route::middleware(['auth'])->group(function () {

            // Main 
            Route::get('/', function () {
                return Auth::check()
                    ? redirect()->route('dashboard_management.dashboards.index')
                    : redirect()->route('login');
            });
            
            require __DIR__.'/system_management.php';
            require __DIR__.'/user_management.php';
            require __DIR__.'/business_management.php';
            require __DIR__.'/dashboard_management.php';
            require __DIR__.'/automation_management.php';
            require __DIR__.'/communication.php';
            require __DIR__.'/notifications.php';
            require __DIR__.'/saved_views.php';
            require __DIR__.'/approvals.php';
            require __DIR__.'/user_preferences.php';
            require __DIR__.'/tools.php';

            // ── Mi Perfil ──
            // Buscador global del topbar (transformadores + clientes del workspace).
            Route::get('search', \App\Http\Controllers\SearchController::class)->name('search')->middleware('throttle:60,1');

            Route::get('profile',           [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile.show');
            Route::put('profile',           [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
            Route::put('profile/preferences', [\App\Http\Controllers\ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');
            Route::put('profile/password',  [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.update_password');
            Route::post('profile/photo',    [\App\Http\Controllers\ProfileController::class, 'updatePhoto'])->name('profile.photo.update');
            // Firma manuscrita propia (imagen privada — sin URL pública).
            Route::post('profile/signature',   [\App\Http\Controllers\ProfileController::class, 'updateSignature'])->name('profile.signature.update');
            Route::get('profile/signature',    [\App\Http\Controllers\ProfileController::class, 'signature'])->name('profile.signature.show');
            Route::delete('profile/signature', [\App\Http\Controllers\ProfileController::class, 'removeSignature'])->name('profile.signature.remove');
            Route::put('profile/autosign',     [\App\Http\Controllers\ProfileController::class, 'updateAutoSign'])->name('profile.autosign.update');
            // Aceptación registrada de Términos/Privacidad (LPDP, versionada).
            Route::post('legal/accept',        [\App\Http\Controllers\ProfileController::class, 'acceptTerms'])->name('legal.accept');
            // Derechos ARCO (LPDP): acceso (descarga de datos) + cancelación (solicitud).
            Route::get('profile/data-export',       [\App\Http\Controllers\ProfileController::class, 'exportData'])->name('profile.data_export')->middleware('throttle:5,1');
            Route::post('profile/deletion-request', [\App\Http\Controllers\ProfileController::class, 'requestDeletion'])->name('profile.deletion_request')->middleware('throttle:deletion-request');

            // "Mi workspace": el admin configura el membrete de informes de SU
            // tenant (dirección, disclaimer, aprobador) sin depender del super.
            Route::middleware('role:super|admin')->group(function () {
                Route::get('workspace', [\App\Http\Controllers\WorkspaceBrandingController::class, 'edit'])->name('workspace.edit');
                Route::put('workspace', [\App\Http\Controllers\WorkspaceBrandingController::class, 'update'])->name('workspace.update');
                Route::post('workspace/logo', [\App\Http\Controllers\WorkspaceBrandingController::class, 'updateLogo'])->name('workspace.logo.update');
            });
        });

        // Públic
        require __DIR__.'/auth_management.php';
        require __DIR__.'/legal_management.php';
    }
);

// ── Portal público de reportes compartidos (sin auth, sin prefijo de idioma) ──
// El idioma lo fija el propio share. Acceso por token + OTP. Ver docs/COMPARTIR-REPORTES.md.
Route::controller(PublicShareController::class)->prefix('r/{token}')->name('share.')->group(function () {
    Route::get('/', 'gate')->name('gate');
    Route::post('/code', 'sendCode')->name('code')->middleware('throttle:share-otp');
    Route::post('/verify', 'verify')->name('verify')->middleware('throttle:10,1');
    Route::get('/view', 'view')->name('view');
    Route::get('/t/{transformer}', 'transformer')->name('transformer'); // detalle de un trafo de la flota
    Route::get('/pdf/{transformer}', 'pdf')->name('pdf');
});

// ── Verificación pública de informes (destino del QR de la carátula) ──
// Confirma autenticidad contra el audit log report_generated. Sin auth.
Route::get('verify/{code?}', \App\Http\Controllers\ReportVerifyController::class)
    ->name('report.verify')->middleware('throttle:20,1');

// ── Manual de uso (HTML autocontenido) ──
// Mismo archivo dentro (menú del avatar) y fuera (login). Público, sin prefijo
// de idioma. Fuente única: docs/manual-usuario.html (no se duplica en public/).
Route::get('manual', fn () => response()->file(base_path('docs/manual-usuario.html')))
    ->name('manual');