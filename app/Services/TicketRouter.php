<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Multi-department Phase 3: decides WHICH department receives a ticket and WHO is told about it.
 *
 * Source of truth = the CATEGORY. A category belongs to exactly one department (categories.department_id), so the
 * receiving department (requests.assigned_department_id) is always taken from the category. The department the
 * person picked on the form is only a cross-check: when it does not match the category the request is refused.
 *
 * Default queue: when nobody is assigned the ticket simply has assigned_to = NULL inside its department. That is the
 * department's "unassigned queue" (MaintenanceRequest::scopeUnassignedQueue). It is also the result for a department
 * that has no staff yet, so a ticket is never lost.
 *
 * Everything here is static and reads only the database, so a test can call it without HTTP.
 */
class TicketRouter
{
    /**
     * @throws TicketRoutingException  mismatched / hidden / unroutable category, department that does not receive
     *                                 tickets, bad subcategory, or an assignee that does not belong there
     */
    public static function route(
        int|Category|null $category,
        ?int $departmentId = null,
        int|Subcategory|null $subcategory = null,
        ?User $assignee = null,
    ): RoutingDecision {
        $category = $category instanceof Category ? $category : ($category === null ? null : Category::find($category));

        if ($category === null || $category->is_hidden) {
            throw new TicketRoutingException('category_id', 'Please choose a category that is available.');
        }

        if ($category->department_id === null) {
            throw new TicketRoutingException('category_id', 'This category does not belong to a department, so the ticket cannot be routed.');
        }

        $department = Department::find($category->department_id);

        if ($department === null || ! $department->is_active || ! $department->is_service_department) {
            throw new TicketRoutingException('assigned_department_id', ($department?->name ?? 'That department').' is not receiving tickets right now.');
        }

        // The category decides. The chosen department must agree with it.
        if ($departmentId !== null && (int) $departmentId !== (int) $department->id) {
            throw new TicketRoutingException('category_id', "The category \"{$category->name}\" belongs to {$department->name}, not to the department you chose.");
        }

        $sub = null;
        if ($subcategory !== null) {
            $sub = $subcategory instanceof Subcategory ? $subcategory : Subcategory::find($subcategory);

            if ($sub === null || $sub->is_hidden || (int) $sub->category_id !== (int) $category->id) {
                throw new TicketRoutingException('subcategory_id', 'Please choose a subcategory that belongs to the chosen category.');
            }
        }

        if ($assignee !== null && ! self::canTake($assignee, $department)) {
            throw new TicketRoutingException('assigned_to', "{$assignee->name} cannot take tickets of {$department->name}.");
        }

        // Optional (off by default): no named assignee -> the department head owns it from the start.
        if ($assignee === null && config('school.routing.auto_assign_head', false)) {
            $head = $department->head;
            $assignee = ($head !== null && self::canTake($head, $department)) ? $head : null;
        }

        return new RoutingDecision($department, $category, $sub, $assignee);
    }

    /** May this user own tickets of the department? Active staff / head / admin that belong to it (Maintenance Office: any of them). */
    public static function canTake(User $user, Department $department): bool
    {
        if (! $user->is_active || ! $user->isMaintenanceTeam()) {
            return false;
        }

        return self::isMaintenance($department->id) || (int) $user->department_id === (int) $department->id;
    }

    /**
     * Who is alerted about a ticket (new ticket, escalation, new message...): the staff and head of the RECEIVING
     * department, not always the Maintenance Admins.
     *
     *  - Maintenance Office (or a ticket without a department): every active Staff / Head / Maintenance Admin, as before.
     *  - Any other department: its active Staff / Head / Maintenance Admin accounts (users.department_id), plus the
     *    department's head_user_id. When that finds nobody and school.routing.fallback_to_maintenance is on
     *    (default), the maintenance team is told instead, so a ticket never goes unnoticed.
     *
     * Visibility is still checked by the notifiers (a person who may not see the ticket is never alerted).
     *
     * @return Collection<int, User>
     */
    public static function receivers(MaintenanceRequest|Department|int|null $target): Collection
    {
        $id = match (true) {
            $target instanceof MaintenanceRequest => $target->assigned_department_id,
            $target instanceof Department => $target->id,
            default => $target,
        };

        if ($id === null || self::isMaintenance((int) $id)) {
            return self::maintenanceTeam();
        }

        $users = User::query()
            ->where('is_active', true)
            ->where('department_id', $id)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', Role::maintenanceSlugs()))
            ->get();

        $head = Department::find($id)?->head;
        if ($head !== null && $head->is_active) {
            $users->push($head);
        }

        $users = $users->unique('id')->values();

        if ($users->isEmpty() && config('school.routing.fallback_to_maintenance', true)) {
            return self::maintenanceTeam();
        }

        return $users;
    }

    /** The department's unassigned queue: open tickets it received that nobody owns yet. */
    public static function unassignedQueue(Department|int $department)
    {
        return MaintenanceRequest::query()->unassignedQueue($department instanceof Department ? $department->id : $department);
    }

    private static function isMaintenance(?int $id): bool
    {
        $maintenance = Department::maintenanceId();

        return $id !== null && $maintenance !== null && (int) $id === $maintenance;
    }

    /** @return Collection<int, User> */
    private static function maintenanceTeam(): Collection
    {
        $maintenanceId = Department::maintenanceId();

        // The Maintenance Office team: the maintenance admin (no department needed) plus staff / head who belong to
        // the Maintenance department or have no department yet. Staff of OTHER departments are not part of it.
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', Role::maintenanceSlugs()))
            ->where(function ($q) use ($maintenanceId) {
                $q->whereHas('role', fn ($r) => $r->where('slug', Role::MAINTENANCE_ADMIN))
                    ->orWhereNull('department_id');

                if ($maintenanceId !== null) {
                    $q->orWhere('department_id', $maintenanceId);
                }
            })
            ->get();
    }
}
