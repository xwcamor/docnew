<?php

namespace App\Http\Controllers\AuthManagement\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class GoogleLoginController extends Controller
{
    public function redirectToGoogle()
    {
        session(['locale' => app()->getLocale()]);
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $locale = $request->segment(1); // ←  'en', 'es', etc.
            app()->setLocale($locale);
            \LaravelLocalization::setLocale($locale);

            $googleUser = Socialite::driver('google')->user();

            // 1. Buscar por google_id
            $user = User::where('google_id', $googleUser->id)->first();

            if ($user) {
                if (!$user->is_active) {
                    return redirect('/login')->with('error', __('auth.account_inactive'));
                }

                Auth::login($user);
                return redirect(\LaravelLocalization::getLocalizedURL($locale, route('user_management.users.index', [], false)));
            }

            // 2. Buscar por email
            $user = User::where('email', $googleUser->email)->first();

            if ($user) {
                $user->update(['google_id' => $googleUser->id]);

                if (!$user->is_active) {
                    return redirect('/login')->with('error', __('auth.account_inactive'));
                }

                Auth::login($user);
                return redirect(\LaravelLocalization::getLocalizedURL($locale, route('user_management.users.index', [], false)));
            }

            // 3. Crear nuevo usuario (NO inicia sesión aún).
            // tenant_id queda NULL — el user no pertenece a ningún workspace
            // hasta que un super/admin lo asigne explícitamente desde el módulo
            // Users + lo active. Sin el null aquí un super que active por error
            // a una cuenta auto-registrada le daría acceso completo al tenant
            // 1 (bug original: tenant_id => 1 hardcoded).
            User::create([
                'name' => $googleUser->name,
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'tenant_id' => null,
                'country_id' => 1,
                'locale_id' => 1,
                'password' => Hash::make(Str::random(24)),
                'slug' => Str::random(22),
                'photo' => $googleUser->avatar,
                'is_active' => false,
            ]);

            return redirect('/login')->with('error', __('auth.account_created_needs_activation'));

        } catch (\Exception $e) {
            return redirect('/login')->with('error', __('auth.login_error'));
        }
        

    }
}