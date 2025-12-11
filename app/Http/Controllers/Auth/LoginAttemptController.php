<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class LoginAttemptController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(LoginRequest $request, StatefulGuard $guard): RedirectResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $remember = $request->boolean('remember');

        $isCredentialsValid = $guard->attempt([
            'email'    => $email,
            'password' => $password,
        ], $remember);

        if ($isCredentialsValid) {
            $request->session()->regenerate();

            return redirect('/dashboard');
        }

        throw ValidationException::withMessages([
            'email' => trans('auth.failed'),
        ])->redirectTo('/login');
    }
}
