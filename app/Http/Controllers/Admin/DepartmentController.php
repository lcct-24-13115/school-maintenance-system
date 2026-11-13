<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Phase 5: department management (admin). Capability: manage_departments — System Administrator ONLY
 * (checked here, like every other Admin controller; everyone else gets 403).
 *
 *  - list / add / edit : name, code, description, "receives tickets", and the department's Head.
 *  - activate / deactivate : an inactive department is no longer offered on the new-ticket / register forms,
 *    but its old tickets keep showing it. Deactivating is REFUSED while the department has OPEN tickets,
 *    unless the admin picks another active department to move those tickets to (a "transfer target").
 *    Finished tickets are never moved: they stay with the department for history.
 *  - delete : a hard delete is REFUSED whenever ANY ticket points at the department. Deactivate instead.
 *    Other records still using it (users, categories, budgets, ...) are refused by the database FK.
 *  - every change is written to the audit log (AuditLogger::DEPARTMENT_*).
 *
 * Department-scoped roles (Phase 4) keep reading users.department_id; nothing here hardcodes a
 * department name or code. Assigning Staff / Heads to a department is still done in Admin > Users
 * (Admin/UserController), which owns role + department selection; this page only sets head_user_id.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeManage($request);

        $departments = Department::query()
            ->with(['head:id,name'])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        // Related tickets can be both filed AND received by the same department. Count the OR query
        // once so the UI never double-counts an open ticket that has the same department on both sides.
        $departments->each(function (Department $department) {
            $department->setAttribute('open_tickets_count', $department->openTickets()->count());
            $department->setAttribute('total_tickets_count', $department->relatedTickets()->count());
        });

        return view('admin.departments.index', [
            'departments' => $departments,
            'targets' => Department::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeManage($request);

        return view('admin.departments.create', [
            'department' => new Department(['is_active' => true, 'is_service_department' => true]),
            // A head can only be assigned once the department exists (see edit()).
            'headOptions' => collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $request->merge(['code' => filled($request->input('code')) ? mb_strtoupper(trim((string) $request->input('code'))) : null]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments', 'code')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_service_department' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'A department with that name already exists.',
            'code.unique' => 'That department code is already used.',
        ]);

        $department = Department::create([
            'name' => trim($data['name']),
            'code' => filled($data['code'] ?? null) ? mb_strtoupper(trim($data['code'])) : null,
            'description' => $data['description'] ?? null,
            'is_service_department' => $request->boolean('is_service_department', true),
            'is_active' => true,
        ]);

        $this->audit($request, AuditLogger::DEPARTMENT_CREATED,
            "Department added: {$department->name} ({$department->code})", $department,
            ['code' => $department->code, 'is_service_department' => $department->is_service_department]);

        return redirect()->route('admin.departments.index')
            ->with('status', "Department \"{$department->name}\" added.");
    }

    public function edit(Request $request, Department $department): View
    {
        $this->authorizeManage($request);

        $staffRoleIds = Role::where('slug', Role::STAFF)->pluck('id');

        return view('admin.departments.edit', [
            'department' => $department,
            'headOptions' => $this->headOptions($department),
            'staff' => User::query()
                ->where('department_id', $department->id)
                ->whereIn('role_id', $staffRoleIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'openTickets' => $department->openTickets()->count(),
            'totalTickets' => $department->relatedTickets()->count(),
            'targets' => Department::active()->where('id', '!=', $department->id)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeManage($request);

        $request->merge(['code' => filled($request->input('code')) ? mb_strtoupper(trim((string) $request->input('code'))) : null]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_service_department' => ['nullable', 'boolean'],
            // The head must be an active Head / Admin of THIS department (or a global administrator).
            'head_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(function ($query) use ($department) {
                $query->where('is_active', true)
                    ->whereIn('role_id', Role::whereIn('slug', [Role::HEAD, Role::MAINTENANCE_ADMIN, Role::SYSTEM_ADMIN])->pluck('id'))
                    ->where(fn ($q) => $q->where('department_id', $department->id)->orWhereNull('department_id'));
            })],
        ], [
            'name.unique' => 'A department with that name already exists.',
            'code.unique' => 'That department code is already used.',
            'head_user_id.exists' => 'Choose an active Department Head / administrator of this department.',
        ]);

        // is_active is deliberately NOT editable here: switching it off must go through deactivate()
        // so the open-ticket / transfer-target rules can never be skipped.
        $before = $department->only(['name', 'code', 'description', 'is_service_department', 'head_user_id']);

        $department->fill([
            'name' => trim($data['name']),
            'code' => filled($data['code'] ?? null) ? mb_strtoupper(trim($data['code'])) : null,
            'description' => $data['description'] ?? null,
            'is_service_department' => $request->boolean('is_service_department'),
            'head_user_id' => isset($data['head_user_id']) ? (int) $data['head_user_id'] : null,
        ]);

        $changed = array_keys($department->getDirty());
        $department->save();

        if ($changed !== []) {
            $this->audit($request, AuditLogger::DEPARTMENT_UPDATED,
                "Department updated: {$department->name}. Changed: ".implode(', ', $changed), $department,
                ['before' => $before, 'changed' => $changed]);
        }

        return back()->with('status', "Department \"{$department->name}\" saved.");
    }

    /** PATCH /admin/departments/{department}/activate */
    public function activate(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($department->is_active) {
            return back()->with('status', "{$department->name} is already active.");
        }

        $department->is_active = true;
        $department->save();

        $this->audit($request, AuditLogger::DEPARTMENT_ACTIVATED,
            "Department activated: {$department->name}", $department);

        return back()->with('status', "{$department->name} is active again and offered on new tickets.");
    }

    /**
     * PATCH /admin/departments/{department}/deactivate
     * Open tickets block deactivation unless `transfer_to` names another ACTIVE department.
     * Only open tickets are moved; finished ones keep the old department for history.
     */
    public function deactivate(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'transfer_to' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
        ], [
            'transfer_to.exists' => 'Choose an active department to move the open tickets to.',
        ]);

        $targetId = isset($data['transfer_to']) ? (int) $data['transfer_to'] : null;

        if ($targetId === (int) $department->id) {
            return back()->with('error', 'Choose a DIFFERENT department to move the open tickets to.');
        }

        $openCount = $department->openTickets()->count();

        if ($openCount > 0 && $targetId === null) {
            return back()->withInput()->with('error',
                "{$department->name} still has {$openCount} open ticket(s). Choose a department to move them to before deactivating, or finish the tickets first.");
        }

        $targetName = $targetId === null ? null : Department::whereKey($targetId)->value('name');
        $movedReceived = 0;
        $movedFiled = 0;

        DB::transaction(function () use ($department, $targetId, &$movedReceived, &$movedFiled) {
            if ($targetId !== null) {
                // Query-builder updates: no status transition runs, the ticket is only re-routed.
                $movedReceived = MaintenanceRequest::query()
                    ->where('assigned_department_id', $department->id)->pending()
                    ->update(['assigned_department_id' => $targetId, 'updated_at' => now()]);

                $movedFiled = MaintenanceRequest::query()
                    ->where('department_id', $department->id)->pending()
                    ->update(['department_id' => $targetId, 'updated_at' => now()]);
            }

            $department->is_active = false;
            $department->save();
        });

        $this->audit($request, AuditLogger::DEPARTMENT_DEACTIVATED,
            "Department deactivated: {$department->name}"
            .($targetName !== null ? ". Open tickets moved to {$targetName}" : '. No open tickets'),
            $department,
            [
                'open_tickets' => $openCount,
                'transfer_to' => $targetId,
                'moved_received' => $movedReceived,
                'moved_filed' => $movedFiled,
            ]);

        return back()->with('status', $targetName !== null
            ? "{$department->name} is inactive. {$openCount} open ticket(s) moved to {$targetName}."
            : "{$department->name} is inactive. It is no longer offered on new tickets.");
    }

    /** DELETE /admin/departments/{department}. A department with ANY ticket keeps its history. */
    public function destroy(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($department->hasAnyTickets()) {
            return back()->with('error',
                "{$department->name} has tickets and cannot be deleted. Deactivate it instead (with a transfer target for open tickets) so the history is kept.");
        }

        // Avoid cascading away unrelated departmental data (budgets / assignments / categories).
        if ($department->users()->exists() || $department->categories()->exists()
            || $department->budgets()->exists() || $department->inventories()->exists()
            || $department->expenditures()->exists() || $department->head()->exists()) {
            return back()->with('error',
                "{$department->name} is still used by people or other records (categories, budgets, inventory...). Deactivate it instead.");
        }

        try {
            $department->delete();
        } catch (QueryException) {
            // users / categories / budgets / inventories / expenditures still reference the row.
            return back()->with('error',
                "{$department->name} is still used by other records (users, categories, budgets...) and cannot be deleted. Deactivate it instead.");
        }

        $this->audit($request, AuditLogger::DEPARTMENT_DELETED,
            "Department deleted: {$department->name} ({$department->code})", $department,
            ['department_id' => $department->id, 'code' => $department->code]);

        return back()->with('status', "Department \"{$department->name}\" deleted.");
    }

    /** Active Heads / Admins of this department, plus global administrators, for the Head dropdown. */
    private function headOptions(Department $department)
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role_id', Role::whereIn('slug', [Role::HEAD, Role::MAINTENANCE_ADMIN, Role::SYSTEM_ADMIN])->pluck('id'))
            ->where(fn ($q) => $q->where('department_id', $department->id)->orWhereNull('department_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function audit(Request $request, string $action, string $description, ?Department $subject = null, ?array $meta = null): void
    {
        AuditLogger::log($action, $description, $subject, $request->user(), $meta);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_departments'), 403, 'You do not have access to this page.');
    }
}
