<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Rules\SchoolEmail;
use App\Services\AccountMailer;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    // Public registration is CLOSED unless ALLOW_PUBLIC_REGISTRATION=true (config/school.php). Closed = both routes answer 404.
    public function create(): View
    {
        abort_unless(config('school.allow_public_registration'), 404);

        return view('auth.register', ['departments' => Department::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('school.allow_public_registration'), 404);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Phase 2: only the school's own email addresses (see App\Rules\SchoolEmail).
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class, new SchoolEmail],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        // Public registration always creates a Requester. Other roles are assigned by an admin.
        $user = new User([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'department_id' => $request->department_id,
            'role_id' => Role::where('slug', Role::REQUESTER)->firstOrFail()->id,
        ]);

        // Account approval: when it is on (default), the person waits until a Maintenance Admin approves the account.
        $needsApproval = (bool) config('school.registration.require_approval', true);

        if ($needsApproval) {
            $user->forceFill(['approval_status' => User::STATUS_PENDING]);
        }

        $user->save();

        event(new Registered($user));

        if ($needsApproval) {
            AccountMailer::pending($user);   // tells the Maintenance Admins; never throws

            return redirect()->route('login')->with('status',
                'Thank you! Your registration was received. A Maintenance Admin must approve your account before you can sign in. '
                .'You will get an email when it is decided.');
        }

        Auth::login($user);

        return redirect()->route($user->dashboardRoute());
    }
}
