<?php

namespace App\Http\Controllers\AuthManagement\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ResetPasswordController extends Controller
{
    /**
     * Display the password reset view for the given token.
     *
     * If no token is present, display the link request form.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null  $token
     * @return \Illuminate\View\View
     */
    public function showResetForm(Request $request, $token = null)
    {
        return inertia('Auth/ResetPassword', [
            'token'   => $token,
            'email'   => $request->email,
            'appName' => config('app.name'),
        ]);
    }

    /**
     * Reset the given user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            // MISMA exigencia que el cambio desde Mi perfil
            // (ProfileController::updatePassword). Antes aquí bastaba con
            // `min:8`, así que por el camino de «olvidé mi contraseña» —el
            // único que tienen los usuarios que vienen del sistema anterior—
            // se podía dejar "aaaaaaaa", y encima la propia pantalla prometía
            // "que lleve letras y números".
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // Rotar el remember_token invalida las cookies de "Recuérdame"
                    // emitidas antes. Sin esto, cambiar la contraseña no echaba a
                    // quien ya tuviera la sesión recordada — que es justo el motivo
                    // por el que se restablece una contraseña.
                    'remember_token' => Str::random(60),
                ])->save();

                // Aviso de seguridad: la clave se cambio via flujo de "olvide".
                $user->notify(new \App\Notifications\PasswordChangedNotification('reset'));
            }
        );

        return $status === Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withErrors(['email' => __($status)]);
    }
}