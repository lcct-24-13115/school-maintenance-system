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
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    // Public registration is CLOSED unless ALLOW_PUBLIC_REGISTRATION=true (config/school.php). Closed = both routes answer 404.
    public function create(): View
    {
        abort_unless(config('school.allow_public_registration'), 404);

        $types = self::availableTypes();
        abort_if($types === [], 404);   // personnel only, but Faculty / Staff was removed from config/registration.php

        return view('auth.register', [
            // Phase 5: inactive departments are not offered on the registration form either.
            'departments' => Department::active()->orderBy('name')->get(),
            'types' => $types,
            'defaultType' => self::defaultType($types),
        ]);
    }

    /**
     * The "I am a ..." options. Personnel only (config school.personnel_only): just Faculty / Staff. Students, parents,
     * alumni and outside organizations are not offered and are refused if a hand-made POST sends them.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function availableTypes(): array
    {
        $types = (array) config('registration.types');

        if (! config('school.personnel_only', true)) {
            return $types;
        }

        return array_intersect_key($types, ['FACULTY/STAFF' => true]);
    }

    /** The preselected option: the configured default when it is offered, otherwise the first one offered. */
    private static function defaultType(array $types): string
    {
        $default = (string) config('registration.default');

        return isset($types[$default]) ? $default : (string) array_key_first($types);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('school.allow_public_registration'), 404);

        // "I am a ...": what the person must fill in depends on the type (config/registration.php).
        // No type sent (old form / old tests) = the default type.
        $types = self::availableTypes();
        abort_if($types === [], 404);
        $type = $request->input('requester_type');
        if (! is_string($type) || $type === '') {
            $type = self::defaultType($types);   // nothing sent (or junk: the 'in' rule below refuses it)
        }
        $rule = $types[$type] ?? $types[self::defaultType($types)];
        $schoolEmailOnly = (bool) ($rule['school_email'] ?? true);

        $emailRules = ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class];
        if ($schoolEmailOnly) {
            // Phase 2: students and staff use the school's own email addresses (see App\Rules\SchoolEmail).
            $emailRules[] = new SchoolEmail;
        }
        if (config('school.personnel_only', true)) {
            // Personnel only: a student-number email (24-12803@...) is a student, who cannot register here.
            $emailRules[] = function (string $attribute, mixed $value, \Closure $fail): void {
                if (preg_match('/^\d+(-\d+)*@/', (string) $value)) {
                    $fail('This system is for LCCT personnel only. Student accounts are not accepted.');
                }
            };
        }

        $request->validate([
            'requester_type' => ['nullable', Rule::in(array_keys($types))],
            'name' => ['required', 'string', 'max:255'],
            'email' => $emailRules,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Phase 5: an inactive department cannot be picked by a hand-made POST.
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('is_active', true)],
            'affiliation' => [($rule['affiliation']['required'] ?? false) ? 'required' : 'nullable', 'string', 'min:2', 'max:190'],
            'purpose' => [($rule['purpose']['required'] ?? false) ? 'required' : 'nullable', 'string', 'min:10', 'max:500'],
        ], [
            'affiliation.required' => 'Enter your '.mb_strtolower($rule['affiliation']['label'] ?? 'details').'.',
            'affiliation.min' => 'Enter at least 2 characters, or leave this blank.',
            'purpose.required' => 'Briefly explain why you need an account (at least 10 characters).',
            'purpose.min' => 'Write at least 10 characters, or leave this blank.',
        ]);

        // Public registration always creates a Requester. Other roles are assigned by an admin.
        $user = new User([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            // The Department dropdown only exists for the types that use it.
            'department_id' => ($rule['department'] ?? false) ? $request->department_id : null,
            // Faculty / Staff register as a Teacher; everyone else as a Requester (not offered when personnel only)
            'role_id' => Role::where('slug', ($type === 'FACULTY/STAFF' || config('school.personnel_only', true)) ? Role::TEACHER : Role::REQUESTER)->firstOrFail()->id,
        ]);

        $user->forceFill([
            'requester_type' => isset($types[$type]) ? $type : self::defaultType($types),
            'affiliation' => $request->input('affiliation'),
            'reg_purpose' => $request->input('purpose'),
        ]);

        // Account approval: when it is on (default), the person waits until a Maintenance Admin approves the account.
        // Someone who did NOT use a school email (alumni, parents, outside organizations ...) ALWAYS waits for approval.
        $needsApproval = (bool) config('school.registration.require_approval', true) || ! $schoolEmailOnly;

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
