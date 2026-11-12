<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use App\Http\Requests\Forms\StoreTicketRequest;
use App\Services\AuditLogger;
use App\Services\FormNotifier;
use App\Services\TicketActions;
use App\Services\TicketRouter;
use App\Services\TicketRoutingException;
use App\Services\TicketWorkflow;
use DomainException;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ticket list (search + filters) and the ticket detail page. The buttons post to TicketActionController. */
class TicketController extends Controller
{
    private const PER_PAGE = 15;

    private const EXPORT_CHUNK = 500;

    /**
     * Multi-department Phase 3: the generic ticket form. Pick the receiving department, then a category of THAT
     * department (the list is filtered in the browser and checked again by TicketRouter on the server).
     * The Maintenance repair form (FO-08) stays at /repairs/create.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', MaintenanceRequest::class);

        $user = $request->user();
        $departments = Department::active()->service()
            ->when(! config('school.routing.allow_maintenance_on_generic_form', false) && Department::maintenanceId() !== null,
                fn ($q) => $q->where('id', '!=', Department::maintenanceId()))
            ->orderBy('name')->get(['id', 'name']);

        return view('tickets.create', [
            'canFlag' => $user->can_do('flag_emergency'),
            'departments' => $departments,
            'categories' => Category::visible()->whereIn('department_id', $departments->pluck('id'))
                ->ordered()->get(['id', 'name', 'department_id']),
            'subcategories' => Subcategory::visible()->ordered()->get(['id', 'category_id', 'name']),
            'allDepartments' => $user->department_id === null ? Department::active()->orderBy('name')->get(['id', 'name']) : null,
        ]);
    }

    /** File a ticket on the generic form. The receiving department comes from the CATEGORY (TicketRouter). */
    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $asked = $data['priority'] === MaintenanceRequest::PRIORITY_EMERGENCY;
        // Same rule as the repair form: only people who may flag an emergency set it; others ask for a review.
        $emergency = $asked && $user->can_do('flag_emergency');
        $reviewAsked = $asked && ! $emergency;

        try {
            $route = TicketRouter::route(
                (int) $data['category_id'],
                (int) $data['assigned_department_id'],
                filled($data['subcategory_id'] ?? null) ? (int) $data['subcategory_id'] : null,
            );

            $ticket = TicketWorkflow::open($user, $route->attributes() + [
                'department_id' => $user->department_id ?? $data['from_department_id'],
                'maintenance_type' => 'request',
                'title' => $data['title'],
                'description' => $data['description'],
                'contact_name' => $data['contact_name'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'is_emergency' => $emergency,
                'emergency_reason' => $emergency ? $data['description'] : null,
                'emergency_review_reason' => $reviewAsked ? $data['description'] : null,
                'routing_note' => $route->note(),
            ], $request->file('files', []));
        } catch (TicketRoutingException $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        FormNotifier::submitted($ticket);   // in-app alert to the staff / head of the RECEIVING department

        $message = "Sent! Your ticket {$ticket->ticket_no} was received by {$route->department->name}.";
        if ($reviewAsked) {
            $message .= ' It was filed as a routine ticket; your emergency request was passed on for review.';
        }

        return redirect()->route('dashboard')->with('status', $message);
    }

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
            'priority' => ['nullable', 'in:emergency,routine'],
            'sort' => ['nullable', 'string', 'max:20'],
            'dir' => ['nullable', 'string'],
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
            'priority' => in_array($request->query('priority'), ['emergency', 'routine'], true) ? $request->query('priority') : null,
            'overdue' => $request->boolean('overdue'),
            'mine' => $request->boolean('mine'),
        ];

        if ($history) {
            // History only has search, status, date and "mine". The rest cannot apply to a finished ticket.
            $filters['category_id'] = $filters['department_id'] = $filters['assigned_to'] = null;
            $filters['emergency'] = $filters['overdue'] = false;
            $filters['priority'] = null;
        }

        $user = $request->user();

        $query = MaintenanceRequest::query()
            ->visibleTo($user)   // never list what the user may not see
            ->with(['requester', 'department', 'category', 'subcategory', 'assignee']);

        $history ? $query->history() : $query->active();

        $this->applyFilters($query, $filters, $user);

        // Sorting: ?sort=<column>&dir=asc|desc. Unknown values are ignored (the default order is used).
        $sortOptions = [
            'ticket' => 'Ticket no', 'status' => 'Status', 'location' => 'Location', 'category' => 'Category',
            'requester' => 'Requester', 'assigned' => 'Assigned to', 'submitted' => 'Submitted',
            'completed' => $history ? 'Closed / completed' : 'Completed',
        ] + ($history ? [] : ['priority' => 'Priority (emergency first)', 'due' => 'Target date']);
        $sort = (string) $request->query('sort');
        $sort = array_key_exists($sort, $sortOptions) ? $sort : null;
        $dir = strtolower((string) $request->query('dir')) === 'desc' ? 'desc' : 'asc';

        if ($sort !== null) {
            $this->applySort($query, $sort, $dir, $history);
        } elseif ($history) {
            $query->orderByDesc('updated_at')->orderByDesc('id');   // most recently finished first
        } else {
            $query->queueOrder();
        }

        $tickets = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'mode' => $history ? 'history' : 'active',
            'listRoute' => $history ? 'tickets.history' : 'tickets.index',
            'canExport' => (bool) $user->can_do('export_reports'),
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'sortOptions' => $sortOptions,
            'hasFilters' => $this->hasFilters($filters),
            'statusOptions' => $statusOptions,
            'categories' => $history ? collect() : Category::orderBy('name')->get(['id', 'name']),
            'departments' => $history ? collect() : Department::orderBy('name')->get(['id', 'name']),
            'assignees' => $history ? collect() : User::query()->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', Role::maintenanceSlugs()))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** "Export CSV" on My Tickets: the same search and filters as the list, but every matching row (not one page). */
    public function export(Request $request): StreamedResponse
    {
        return $this->exportList($request, false);
    }

    /** "Export CSV" on Ticket History. */
    public function exportHistory(Request $request): StreamedResponse
    {
        return $this->exportList($request, true);
    }

    /**
     * Ticket list as CSV. Needs the export_reports capability (403 otherwise). The rows come from
     * visibleTo(), so a confidential ticket only reaches roles that may see it. Logged to audit_logs.
     */
    private function exportList(Request $request, bool $history): StreamedResponse
    {
        Gate::authorize('viewAny', MaintenanceRequest::class);
        $user = $request->user();
        abort_unless($user?->can_do('export_reports'), 403, 'You do not have access to this page.');

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', 'in:emergency,routine'],
            'sort' => ['nullable', 'string', 'max:20'],
            'dir' => ['nullable', 'string'],
            'emergency' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
        ]);

        // same rule as the list page: each list only knows its own statuses
        $statusOptions = $history ? TicketStatus::terminalOptions()
            : array_diff_key(TicketStatus::options(), TicketStatus::terminalOptions());
        $statuses = array_values(array_intersect(
            array_filter(Arr::wrap($request->query('status')), 'is_string'),
            array_keys($statusOptions)
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
            'priority' => in_array($request->query('priority'), ['emergency', 'routine'], true) ? $request->query('priority') : null,
            'overdue' => $request->boolean('overdue'),
            'mine' => $request->boolean('mine'),
        ];

        if ($history) {
            $filters['category_id'] = $filters['department_id'] = $filters['assigned_to'] = null;
            $filters['emergency'] = $filters['overdue'] = false;
            $filters['priority'] = null;
        }

        $query = MaintenanceRequest::query()->visibleTo($user);
        $history ? $query->history() : $query->active();
        $this->applyFilters($query, $filters, $user);

        $rows = (clone $query)->count();
        $list = $history ? 'history' : 'my tickets';

        // Recorded BEFORE the file starts, so even a download that is cancelled half way is on the trail.
        AuditLogger::log(
            AuditLogger::EXPORT,
            sprintf('Exported ticket list (CSV, %s): %d %s', $list, $rows, $rows === 1 ? 'ticket' : 'tickets'),
            user: $user,
            meta: ['report' => 'ticket_list', 'format' => 'csv', 'list' => $history ? 'history' : 'active', 'rows' => $rows],
        );

        $query->with(['category:id,name', 'department:id,name', 'requester:id,name', 'assignee:id,name']);

        $filename = ($history ? 'ticket-history-' : 'tickets-').now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, Csv::BOM);
            Csv::write($out, [
                'Ticket no', 'Status', 'Category', 'Department', 'Requester', 'Assigned to',
                'Emergency', 'Confidential', 'Overdue', 'Location', 'Description',
                'Submitted', 'Due', 'Approved', 'Completed', 'Closed',
            ]);

            $date = fn ($d) => $d?->format('Y-m-d H:i');

            $query->chunkById(self::EXPORT_CHUNK, function ($tickets) use ($out, $date) {
                /** @var MaintenanceRequest $t */
                foreach ($tickets as $t) {
                    Csv::write($out, [
                        $t->ticket_no, $t->status->label(), $t->category?->name, $t->department?->name,
                        $t->requester?->name, $t->assignee?->name,
                        (bool) $t->is_emergency, (bool) $t->is_confidential, $t->isOverdue(),
                        $t->location, $t->description,
                        $date($t->submitted_at), $date($t->due_at), $date($t->president_approved_at), $date($t->completed_at), $date($t->closed_at),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(MaintenanceRequest $ticket): View
    {
        // {ticket} is already resolved through visibleTo() (404 when hidden); the policy is the second lock.
        Gate::authorize('view', $ticket);

        $ticket->load([
            'requester.department', 'department', 'category', 'subcategory', 'assignee', 'president', 'emergencyFlagger',
            'items', 'attachments', 'statusLogs.user',
        ]);

        // Phase 11: the conversation as THIS user may read it (internal notes only for staff)
        $ticket->setRelation('replies', $ticket->conversationFor(request()->user())->with(['author', 'attachments'])->get());

        $actions = TicketActions::for($ticket, request()->user());

        return view('tickets.show', [
            'ticket' => $ticket,
            'actions' => $actions,
            // only loaded when the Assign / Reassign / Transfer form will be shown
            'staff' => array_intersect([TicketActions::ASSIGN, TicketActions::REASSIGN, TicketActions::TRANSFER], $actions) !== []
                ? TicketActions::assignableStaff()
                : collect(),
        ]);
    }

    /**
     * One column of the list. Names of related records (category, requester, assignee) are sorted with a sub-select,
     * never a join, because visibleTo() uses unqualified column names. The id is the tie-breaker, so pages are stable.
     */
    private function applySort(Builder $query, string $sort, string $dir, bool $history): void
    {
        $own = (new MaintenanceRequest)->getTable();
        $name = fn (string $model, string $fk) => $model::query()->select('name')
            ->whereColumn((new $model)->getTable().'.id', $own.'.'.$fk);

        match ($sort) {
            'ticket' => $query->orderBy('ticket_no', $dir),
            'status' => $query->orderBy('status', $dir),
            'location' => $query->orderBy('location', $dir),
            'category' => $query->orderBy($name(Category::class, 'category_id'), $dir),
            'requester' => $query->orderBy($name(User::class, 'requester_id'), $dir),
            'assigned' => $query->orderBy($name(User::class, 'assigned_to'), $dir),
            'submitted' => $query->orderBy('submitted_at', $dir),
            'completed' => $history
                ? $query->orderByRaw('COALESCE(closed_at, completed_at) '.($dir === 'desc' ? 'desc' : 'asc'))
                : $query->orderBy('completed_at', $dir),
            'priority' => $query->orderBy('is_emergency', $dir === 'asc' ? 'desc' : 'asc'),   // ascending = emergency first
            'due' => $query->orderByRaw('due_at IS NULL')->orderBy('due_at', $dir),
            default => null,
        };

        $query->orderBy('id', $dir);
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
        if ($f['assigned_to'] === 'me') {
            $query->where('assigned_to', $user->id);
        } elseif ($f['assigned_to'] === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif (ctype_digit((string) $f['assigned_to'])) {
            $query->where('assigned_to', (int) $f['assigned_to']);
        }
        $priority = $f['priority'] ?? null;
        if ($f['emergency'] || $priority === 'emergency') {
            $query->where('is_emergency', true);
        } elseif ($priority === 'routine') {
            $query->where('is_emergency', false);
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
