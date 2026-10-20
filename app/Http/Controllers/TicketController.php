<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Ticket list (search + filters) and the ticket detail page. The buttons post to TicketActionController. */
class TicketController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MaintenanceRequest::class);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'string', 'max:20'],
            'emergency' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
        ]);

        // status may be one value or a list (dashboard cards link with several); unknown values are ignored
        $statuses = array_values(array_intersect(
            array_filter(Arr::wrap($request->query('status')), 'is_string'),
            array_keys(TicketStatus::options())
        ));

        $filters = [
            'q' => trim((string) $request->query('q')),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $statuses,
            'category_id' => $request->query('category_id'),
            'department_id' => $request->query('department_id'),
            'assigned_to' => $request->query('assigned_to'),
            'emergency' => $request->boolean('emergency'),
            'mine' => $request->boolean('mine'),
        ];

        $user = $request->user();

        $query = MaintenanceRequest::query()
            ->visibleTo($user)   // never list what the user may not see
            ->with(['requester', 'department', 'category', 'assignee']);

        $this->applyFilters($query, $filters, $user);

        $tickets = $query->queueOrder()->paginate(self::PER_PAGE)->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'hasFilters' => $this->hasFilters($filters),
            'statusOptions' => TicketStatus::options(),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'assignees' => User::query()->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::MAINTENANCE_ADMIN))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(MaintenanceRequest $ticket): View
    {
        // {ticket} is already resolved through visibleTo() (404 when hidden); the policy is the second lock.
        Gate::authorize('view', $ticket);

        $ticket->load([
            'requester.department', 'department', 'category', 'assignee', 'president', 'emergencyFlagger',
            'items', 'attachments', 'statusLogs.user',
        ]);

        $actions = TicketActions::for($ticket, request()->user());

        return view('tickets.show', [
            'ticket' => $ticket,
            'actions' => $actions,
            // only loaded when the Assign / Reassign form will be shown
            'staff' => array_intersect([TicketActions::ASSIGN, TicketActions::REASSIGN], $actions) !== []
                ? TicketActions::assignableStaff()
                : collect(),
        ]);
    }

    private function applyFilters(Builder $query, array $f, User $user): void
    {
        if ($f['q'] !== '') {
            $like = '%'.$f['q'].'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('ticket_no', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('location', 'like', $like)
                    ->orWhere('reason', 'like', $like)
                    ->orWhereHas('requester', fn (Builder $r) => $r->where('name', 'like', $like));
            });
        }

        if ($f['from']) {
            $query->where('submitted_at', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if ($f['to']) {
            $query->where('submitted_at', '<=', Carbon::parse($f['to'])->endOfDay());
        }
        if ($f['status']) {
            $query->whereIn('status', $f['status']);
        }
        if ($f['category_id']) {
            $query->where('category_id', $f['category_id']);
        }
        if ($f['department_id']) {
            $query->where('department_id', $f['department_id']);
        }
        if ($f['assigned_to'] === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif (ctype_digit((string) $f['assigned_to'])) {
            $query->where('assigned_to', (int) $f['assigned_to']);
        }
        if ($f['emergency']) {
            $query->where('is_emergency', true);
        }
        if ($f['mine']) {
            $query->where('requester_id', $user->id);
        }
    }

    private function hasFilters(array $f): bool
    {
        foreach ($f as $value) {
            if ($value !== '' && $value !== null && $value !== false && $value !== []) {
                return true;
            }
        }

        return false;
    }
}
