<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 3: when config school.registration.require_verification is true, a signed-in user whose email is not verified
 * can only open the verification pages, the forced password change page, and logout. Off by default.
 * Added to the whole web group in bootstrap/app.php (after EnsurePasswordChanged).
 */
class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('school.registration.require_verification') || ! $user || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($request->routeIs('verification.*', 'password.force', 'password.force.update', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(423, 'Verify your email address first.');
        }

        return redirect()->route('verification.notice');
    }
}
