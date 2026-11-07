<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Forgot password WITHOUT email: the person proves who they are with their email + employee/ID number
 * and chooses the new password on the same page. No link is sent.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'employee_no' => ['required', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $key = 'forgot-password|'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw ValidationException::withMessages([
                'email' => "Too many attempts. Please try again in {$minutes} minute(s).",
            ]);
        }

        $user = User::where('email', $data['email'])
            ->where('is_active', true)
            ->whereNotNull('employee_no')
            ->first();

        // Same message whether the email or the ID number is wrong, so nobody can probe which emails exist.
        if (! $user || ! hash_equals(Str::lower(trim($user->employee_no)), Str::lower(trim($data['employee_no'])))) {
            RateLimiter::hit($key, 15 * 60);

            throw ValidationException::withMessages([
                'email' => 'We could not match that email and ID number. Please check them or ask the Administrator to reset your password.',
            ]);
        }

        RateLimiter::clear($key);

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        AuditLogger::log(AuditLogger::PASSWORD_RESET, 'Password reset from the forgot-password page', $user, $user);

        return redirect()->route('login')->with('status', 'Your password has been changed. You can sign in now.');
    }
}
