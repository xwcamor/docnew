<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureUserActive — cierra la sesión de un usuario desactivado (is_active =
 * false) que sigue navegando con una sesión abierta.
 *
 * El login (con contraseña y con Google) ya bloquea a los inactivos al entrar;
 * esto cubre la desactivación EN CALIENTE: un admin desactiva a alguien y ese
 * alguien tenía sesión iniciada. Sin esto seguiría operando hasta cerrar sesión.
 *
 * Solo actúa con sesión iniciada — invitados (login, portal público) pasan.
 */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->is_active === false) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', __('auth.account_inactive'));
        }

        return $next($request);
    }
}
