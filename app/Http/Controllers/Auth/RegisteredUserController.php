<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Rules\SchoolEmail;
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
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'department_id' => $request->department_id,
            'role_id' => Role::where('slug', Role::REQUESTER)->firstOrFail()->id,
        ]);

        event(new Registered($user));
        Auth::login($user);

        return redirect()->route($user->dashboardRoute());
    }
}
