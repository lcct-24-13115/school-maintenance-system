<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Phase 2: first sign-in with a temporary password. The user must choose their own before using the system. */
class ForcePasswordChangeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route($request->user()->dashboardRoute());
        }

        return view('auth.force-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ], [
            'password.different' => 'Choose a password that is different from the temporary one.',
        ]);

        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make($request->string('password')->toString()),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        AuditLogger::log('password_changed', 'Changed the temporary password', $user, $user);

        return redirect()->route($user->dashboardRoute())->with('status', 'Password changed. Welcome!');
    }
}
