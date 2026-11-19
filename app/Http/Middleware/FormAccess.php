<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may use the Use of Facilities & Equipment form and the Requisition Slip.
 *   ->middleware(FormAccess::class . ':file')   file a new form (school.form_roles only)
 *   ->middleware(FormAccess::class . ':open')   open / sign one (school.form_roles + school.form_signer_roles)
 * The lists are read on every request, so changing config/school.php (or a test overriding it) takes effect at once.
 */
class FormAccess
{
    public function handle(Request $request, Closure $next, string $mode = 'file'): Response
    {
        $user = $request->user();

        abort_unless($user && $user->is_active, 403, 'Your account is not active.');

        $roles = (array) config('school.form_roles', []);

        if ($mode === 'open') {
            $roles = array_merge($roles, (array) config('school.form_signer_roles', []));
        }

        abort_unless($roles !== [] && $user->hasRole(...$roles), 403, 'You do not have access to this page.');

        return $next($request);
    }
}
