<?php

// Source folder: app\Http\Controllers\AuthManagement\Auth\
namespace App\Http\Controllers\AuthManagement\Auth;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class LoginController extends Controller
{
    // ------------------------------
    // Login form (Inertia)
    // ------------------------------
    public function login()
    {
        // Build a {code => localized URL} map for the language selector.
        $locales = collect(LaravelLocalization::getSupportedLocales())
            ->mapWithKeys(fn ($_, $code) => [
                $code => LaravelLocalization::getLocalizedURL($code, route('login'), [], true),
            ])
            ->toArray();

        return inertia('Auth/Login', [
            'appName' => config('app.name'),
            'locale'  => app()->getLocale(),
            'locales' => $locales,
        ]);
    }

    // Login attempt — validation errors flow back to Inertia via `form.errors`.
    public function loginAccess(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);
        $remember = $request->boolean('remember');

        // Rate limit clave por email+IP. Settings configurables desde la UI
        // del super: security.max_login_attempts y security.lockout_minutes.
        $throttleKey   = $this->throttleKey($credentials['email'], $request->ip());
        $maxAttempts   = max(1, Setting::getInt('security.max_login_attempts', 5));
        $lockoutSecs   = max(60, Setting::getInt('security.lockout_minutes', 15) * 60);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'email' => __('auth.locked', [
                    'minutes' => max(1, (int) ceil($seconds / 60)),
                ]),
            ]);
        }

        if (Auth::attempt($credentials, $remember)) {
            // Credenciales válidas pero cuenta desactivada: NO se inicia sesión.
            // (Los eliminados ya los bloquea el SoftDeletes en el provider; los
            // inactivos hay que bloquearlos explícitamente — mismo criterio que
            // el login con Google.)
            if (! Auth::user()->is_active) {
                Auth::logout();
                throw ValidationException::withMessages([
                    'email' => __('auth.account_inactive'),
                ]);
            }

            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();
            $request->session()->flash('success', __('auth.start_session'));

            // Dashboard is still a Blade page — Inertia::location() forces a
            // hard navigation (X-Inertia-Location) so the browser fully reloads
            // and renders the legacy AdminLTE shell instead of trying to swap
            // it inside the Inertia SPA bubble.
            return Inertia::location(route('dashboard_management.dashboards.index'));
        }

        RateLimiter::hit($throttleKey, $lockoutSecs);

        // Throw validation exception so the email field shows the error and
        // Inertia's `form.errors.email` is populated automatically.
        throw ValidationException::withMessages([
            'email' => __('auth.error_session'),
        ]);
    }

    /**
     * Throttle key normalizado por email (lowercase + sin acentos) + IP.
     * Evita que el atacante rote mayúsculas/minúsculas para esquivar el lockout.
     */
    private function throttleKey(string $email, ?string $ip): string
    {
        return Str::transliterate(Str::lower($email)) . '|' . ($ip ?: 'unknown');
    }

    // ------------------------------
    // Logout
    // ------------------------------
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('success', __('auth.end_session'));
    }
}
