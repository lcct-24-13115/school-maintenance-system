<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personnel only (config school.personnel_only): this system is not open to Student accounts.
 * A Student (requester role) who is still signed in, for example from before this rule, is signed out at the
 * next request and sent to the sign-in page with a message. Added to the whole web group in bootstrap/app.php.
 */
class EnsurePersonnel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isBlockedAsStudent()) {
            return $next($request);
        }

        if ($request->routeIs('logout')) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'This system is for LCCT personnel only. Student accounts cannot sign in.';

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
