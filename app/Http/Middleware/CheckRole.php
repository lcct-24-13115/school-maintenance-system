<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage in routes: ->middleware(CheckRole::class . ':finance,president') */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user && $user->is_active, 403, 'Your account is not active.');
        abort_unless($user->hasRole(...$roles), 403, 'You do not have access to this page.');

        return $next($request);
    }
}
