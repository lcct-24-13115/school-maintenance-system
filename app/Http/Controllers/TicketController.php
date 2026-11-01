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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Ticket list (search + filters) and the ticket detail page. The buttons post to TicketActionController. */
class TicketController extends Controller
{
    private const PER_PAGE = 15;

    /** "My Tickets": the active tickets only. Closed / Cancelled / Disapproved are in history(). */
    public function index(Request $request): View|RedirectResponse
    {
        return $this->listing($request, false);
    }

    /** Phase 14, "Ticket History": Closed / Cancelled / Disapproved, newest first. Same visibleTo() rule as the list. */
    public function history(Request $request): View|RedirectResponse
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $history): View|RedirectResponse
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
            'overdue' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
        ]);

        // status may be one value or a list (dashboard cards link with several); unknown values are ignored
        $statuses = array_values(array_intersect(
            array_filter(Arr::wrap($request->query('status')), 'is_string'),
            array_keys(TicketStatus::options())
        ));

        // An old link such as /tickets?status[]=closed (bookmark, dashboard card) goes to the history page.
        if (! $history && array_intersect($statuses, TicketStatus::terminalValues()) !== []) {
            return redirect()->route('tickets.history', $request->query());
        }

        // Each page only knows its own statuses; the other ones in the URL are ignored.
        $statusOptions = $history ? TicketStatus::terminalOptions()
            : array_diff_key(TicketStatus::options(), TicketStatus::terminalOptions());
        $statuses = array_values(array_intersect($statuses, array_keys($statusOptions)));

        $filters = [
            'q' => trim((string) $request->query('q')),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $statuses,
            'category_id' => $request->query('category_id'),
            'department_id' => $request->query('department_id'),
            'assigned_to' => $request->query('assigned_to'),
            'emergency' => $request->boolean('emergency'),
            'overdue' => $request->boolean('overdue'),
            'mine' => $request->boolean('mine'),
        ];

        if ($history) {
            // History only has search, status, date and "mine". The rest cannot apply to a finished ticket.
            $filters['category_id'] = $filters['department_id'] = $filters['assigned_to'] = null;
            $filters['emergency'] = $filters['overdue'] = false;
        }

        $user = $request->user();

        $query = MaintenanceRequest::query()
            ->visibleTo($user)   // never list what the user may not see
            ->with(['requester', 'department', 'category', 'assignee']);

        $history ? $query->history() : $query->active();

        $this->applyFilters($query, $filters, $user);

        $tickets = ($history
            ? $query->orderByDesc('updated_at')->orderByDesc('id')   // most recently finished first
            : $query->queueOrder()
        )->paginate(self::PER_PAGE)->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'mode' => $history ? 'history' : 'active',
            'listRoute' => $history ? 'tickets.history' : 'tickets.index',
            'filters' => $filters,
            'hasFilters' => $this->hasFilters($filters),
            'statusOptions' => $statusOptions,
            'categories' => $history ? collect() : Category::orderBy('name')->get(['id', 'name']),
            'departments' => $history ? collect() : Department::orderBy('name')->get(['id', 'name']),
            'assignees' => $history ? collect() : User::query()->where('is_active', true)
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

        // Phase 11: the conversation as THIS user may read it (internal notes only for staff)
        $ticket->setRelation('replies', $ticket->conversationFor(request()->user())->with(['author', 'attachments'])->get());

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
        if ($f['overdue']) {
            $query->overdue();
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
