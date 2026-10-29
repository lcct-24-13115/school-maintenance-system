<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 2: while users.must_change_password is true the only pages that work are the "choose a new password"
 * page and logout. Everything else redirects there (background requests get 423 Locked instead).
 * Added to the whole web group in bootstrap/app.php.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs('password.force', 'password.force.update', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(423, 'You must change your temporary password first.');
        }

        return redirect()->route('password.force');
    }
}
