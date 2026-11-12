<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Rules\SchoolEmail;
use App\Services\AccountMailer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * User / team management (Phase 6). Capability: manage_users (Maintenance Admin only; Head, Staff and everyone else get 403).
 *
 *  - add        : creates the account with a TEMPORARY password. must_change_password = true, so the first sign-in
 *                 goes straight to "choose a new password". The password is shown ONCE on the next page.
 *  - activate / deactivate : an inactive account cannot sign in and its open sessions are ended.
 *  - reset      : a new temporary password (must be changed at next sign-in).
 *  - role / department : changed from the row's Edit panel.
 *
 *  - approve / reject : a self-registered account is Pending until an admin decides. A pending person cannot sign in.
 *                 Approving also works on a rejected account (the admin may change their mind). A rejection can carry a reason
 *                 that the person sees. Both are written to the audit log and e-mailed to the person (AccountMailer).
 *
 * Guards: you cannot deactivate yourself, change your own role, or reset your own password here (use Profile).
 * Because of that, the admin using this page always stays an active admin, so the system can never lock itself out.
 *
 * Audit: create / role / department / activate / deactivate are written by UserObserver (it watches the model).
 * The password reset is written here (AuditLogger::USER_PASSWORD_RESET). A password is NEVER written to the audit log.
 */
class UserController extends Controller
{
    public const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $this->authorizeManage($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $users = User::query()
            ->with(['role:id,name,slug', 'department:id,name,code'])
            ->when($filters['q'] ?? null, function ($query, $term) {
                $term = addcslashes($term, '%_\\');

                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('employee_no', 'like', "%{$term}%");
                });
            })
            ->when($filters['role'] ?? null, fn ($query, $id) => $query->where('role_id', $id))
            ->when($filters['department'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->where('approval_status', '!=', User::STATUS_PENDING)   // pending people have their own panel above the list
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.users', [
            'users' => $users,
            'pending' => User::query()->pending()->with('department:id,name,code')->oldest()->oldest('id')->limit(50)->get(),
            'pendingTotal' => User::query()->pending()->count(),
            'filters' => $filters,
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new SchoolEmail, Rule::unique('users', 'email')],
            'employee_no' => ['nullable', 'string', 'max:50', Rule::unique('users', 'employee_no')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ], [
            'email.unique' => 'An account with this email already exists.',
            'employee_no.unique' => 'This employee number is already used by another account.',
        ]);

        $temporary = self::temporaryPassword();

        $user = new User([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'employee_no' => $data['employee_no'] ?? null,
            'role_id' => $data['role_id'],
            'department_id' => $data['department_id'] ?? null,
            'password' => $temporary,            // the "hashed" cast hashes it
            'is_active' => true,
        ]);

        // Admin-made accounts are trusted (config/school.php: seeded and admin-made accounts get email_verified_at).
        $user->forceFill(['must_change_password' => true, 'email_verified_at' => now()]);
        $user->save();                           // UserObserver@created writes the audit row

        return redirect()->route('admin.users.index')
            ->with('status', "Account created for {$user->name}.")
            ->with('temp_password', ['name' => $user->name, 'email' => $user->email, 'password' => $temporary]);
    }

    /** Change role and / or department. */
    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        if ($user->is($request->user()) && (int) $data['role_id'] !== (int) $user->role_id) {
            return back()->with('error', 'You cannot change your own role. Ask another Maintenance Admin.');
        }

        $user->role_id = (int) $data['role_id'];
        $user->department_id = isset($data['department_id']) ? (int) $data['department_id'] : null;

        if (! $user->isDirty()) {
            return back()->with('status', "No changes for {$user->name}.");
        }

        $user->save();                           // UserObserver@updated writes role_changed / user_updated

        return back()->with('status', "{$user->name} updated.");
    }

    /** Approve a registration (pending, or one that was rejected). The person can sign in right away. */
    public function approve(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($user->isApproved()) {
            return back()->with('status', "{$user->name} is already approved.");
        }

        $user->forceFill([
            'approval_status' => User::STATUS_APPROVED,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'rejection_reason' => null,
        ])->save();

        AuditLogger::log(
            AuditLogger::ACCOUNT_APPROVED,
            "Account approved: {$user->name} ({$user->email})",
            $user,
            $request->user(),
        );

        AccountMailer::decided($user, true);

        return back()->with('status', "{$user->name} was approved and can now sign in.");
    }

    /** Reject a registration that is waiting. The optional reason is shown to the person. */
    public function reject(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if (! $user->isPending()) {
            return back()->with('error', 'Only an account that is waiting for approval can be rejected. To stop an active person, deactivate the account instead.');
        }

        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;

        $user->forceFill([
            'approval_status' => User::STATUS_REJECTED,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        // The description holds no reason text: it stays on the account (and in the e-mail to the person).
        AuditLogger::log(
            AuditLogger::ACCOUNT_REJECTED,
            "Account rejected: {$user->name} ({$user->email})",
            $user,
            $request->user(),
            meta: ['has_reason' => $reason !== null],
        );

        AccountMailer::decided($user, false, $reason);

        return back()->with('status', "{$user->name}'s registration was rejected.");
    }

    /** Activate or deactivate (PATCH /admin/users/{user}/status, is_active=1|0). Same value twice does nothing. */
    public function status(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage($request);

        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        if (! $active && $user->is($request->user())) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->is_active = $active;

        if (! $active) {
            // a "remember me" cookie must stop working too
            $user->remember_token = Str::random(60);
        }

        $user->save();                           // UserObserver@updated writes user_deactivated / user_reactivated

        if (! $active) {
            $this->endSessions($user);
        }

        return back()->with('status', $active
            ? "{$user->name} is active again."
            : "{$user->name} is deactivated and can no longer sign in.");
    }

    /** New temporary password. The account must change it at the next sign-in. */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($user->is($request->user())) {
            return back()->with('error', 'Change your own password from your Profile page.');
        }

        $temporary = self::temporaryPassword();

        $user->forceFill([
            'password' => $temporary,            // hashed by the cast
            'must_change_password' => true,
            'password_changed_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $this->endSessions($user);

        // The description never contains the password itself.
        AuditLogger::log(
            AuditLogger::USER_PASSWORD_RESET,
            "Temporary password issued for {$user->name} ({$user->email})",
            $user,
            $request->user(),
        );

        return back()
            ->with('status', "Password reset for {$user->name}.")
            ->with('temp_password', ['name' => $user->name, 'email' => $user->email, 'password' => $temporary]);
    }

    /**
     * 12 characters, easy to read out loud: no 0/O, 1/l/I. Always has upper case, lower case and digits.
     * random_int is cryptographically secure (str_shuffle is not, so the shuffle is done by hand).
     */
    public static function temporaryPassword(): string
    {
        $pick = static function (string $set, int $count): array {
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $out[] = $set[random_int(0, strlen($set) - 1)];
            }

            return $out;
        };

        $chars = array_merge(
            $pick('ABCDEFGHJKLMNPQRSTUVWXYZ', 4),
            $pick('abcdefghijkmnopqrstuvwxyz', 5),
            $pick('23456789', 3),
        );

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** Sign the person out everywhere (database sessions only; other drivers cannot be searched by user). */
    private function endSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Live badge on the "Users" menu item: how many accounts wait for approval. JSON, polled by public/js/pending-badge.js. */
    public function pendingCount(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorizeManage($request);

        return response()
            ->json(['count' => User::query()->pending()->count()])
            ->header('Cache-Control', 'no-store');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_users'), 403, 'You do not have access to this page.');
    }
}
