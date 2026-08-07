<?php

namespace App\Http\Controllers\AuthManagement\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    /**
     * Display the form to request a password reset link (Inertia).
     */
    public function showLinkRequestForm()
    {
        return inertia('Auth/ForgotPassword', [
            'appName' => config('app.name'),
        ]);
    }

    /**
     * Send a reset link to the given user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
                    ? back()->with([
                        'success' => __($status),
                        'sent_to' => $request->input('email'),
                    ])
                    : back()->withErrors(['email' => __($status)]);
    }
}