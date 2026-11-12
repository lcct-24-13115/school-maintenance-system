<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Inventory;
use App\Models\MaintenanceRequest;
use App\Models\RequestStatusLog;
use App\Services\ReportMetrics;
use App\Services\TicketWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** /dashboard sends each user to the dashboard of their role. */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->dashboardRoute());
    }

    public function requester(Request $request): View
    {
        $user = $request->user();
        $mine = $user->requests();   // own tickets: always visible to their owner

        // A department head's dashboard also covers the department, so its list link adds ?mine=1
        $own = fn (array $statuses = []) => route('tickets.index', ['mine' => 1] + ($statuses ? ['status' => $statuses] : []));

        // Phase 7: Escalated waits for the authorizer; Waiting for User and Reopened count as "in progress".
        $waiting = [TicketStatus::Submitted->value, TicketStatus::PendingAuthorization->value, TicketStatus::Escalated->value];
        $active = [
            TicketStatus::Approved->value, TicketStatus::Assigned->value, TicketStatus::InProgress->value, TicketStatus::WorkCompleted->value,
            TicketStatus::WaitingForUser->value, TicketStatus::Reopened->value,
        ];

        return $this->show('Requester Dashboard', [
            'My tickets' => $this->card((clone $mine)->active()->count(), $own()),   // Phase 14: active only; finished ones are in Ticket History
            'Waiting for authorization' => $this->card((clone $mine)->whereIn('status', $waiting)->count(), $own($waiting)),
            'In progress' => $this->card((clone $mine)->whereIn('status', $active)->count(), $own($active)),
            'Closed' => $this->card((clone $mine)->where('status', TicketStatus::Closed->value)->count(), route('tickets.history', ['mine' => 1, 'status' => [TicketStatus::Closed->value]])),
        ]);
    }

        /** Staff, Head and Maintenance Admin: the full dashboard (Needs attention, Priority queue, trend, service levels, activity, workload). */
    public function maintenance(Request $request): View
    {
        $user = $request->user();
        $visible = fn () => MaintenanceRequest::query()->visibleTo($user);   // every number starts here: nobody counts a ticket they may not see

        $assignable = array_map(fn (TicketStatus $s) => $s->value, TicketWorkflow::assignableStatuses());

        // "Needs attention" tiles, each linking to the ticket list with the same filter
        $attention = [
            'Urgent' => $this->card($visible()->active()->where('is_emergency', true)->count(), route('tickets.index', ['emergency' => 1])),
            'Overdue' => $this->card($visible()->overdue()->count(), route('tickets.index', ['overdue' => 1])),
            'Unassigned' => $this->card(
                $visible()->active()->whereNull('assigned_to')->whereIn('status', $assignable)->count(),
                route('tickets.index', ['assigned_to' => 'unassigned', 'status' => $assignable])
            ),
            'Assigned to me' => $this->card($visible()->active()->where('assigned_to', $user->id)->count(), route('tickets.index', ['assigned_to' => $user->id])),
        ];

        // Service levels + trend: the last 30 days
        $window = new ReportMetrics($user, now()->subDays(29)->toDateString());
        $first = $window->firstResponse();
        $service = [
            'first_response' => $first['avg_hours'],
            'answered' => $first['answered'],
            'of' => $first['of'],
            'resolution' => $window->averageHours('submitted_at', 'completed_at'),
        ];
        $trend = $window->daily();

        // Priority queue: active tickets with the nearest target date first (no target date last)
        $queue = $visible()->pending()
            ->with(['category:id,name', 'assignee:id,name'])
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('is_emergency')->orderBy('id')
            ->limit(8)->get();

        // Recent activity (internal notes are left out)
        $activity = RequestStatusLog::query()
            ->whereIn('request_id', $visible()->select('id'))
            ->where('action', '!=', RequestStatusLog::ACTION_NOTE)
            ->with(['user:id,name', 'request:id,ticket_no'])
            ->latest('id')->limit(8)->get();

        // Staff workload: for the Head and the Admin only (Staff only see their own tickets anyway)
        $workload = $user->isStaffOnly() ? null : array_slice((new ReportMetrics($user))->workload(), 0, 8);

        $lastUpdate = $visible()->orderByDesc('updated_at')->first(['updated_at'])?->updated_at;

        return view('dashboards.maintenance', [
            'title' => 'Maintenance Dashboard',
            'attention' => $attention,
            'service' => $service,
            'trend' => $trend,
            'queue' => $queue,
            'activity' => $activity,
            'workload' => $workload,
            'lastUpdate' => $lastUpdate,
        ]);
    }

    public function finance(Request $request): View
    {
        $user = $request->user();
        $visible = fn () => MaintenanceRequest::query()->visibleTo($user);
        $pending = TicketStatus::PendingAuthorization->value;
        $completed = TicketStatus::WorkCompleted->value;

        return $this->show('Finance Dashboard', [
            'Pending tickets' => $this->card($visible()->pending()->count(), route('tickets.index', ['status' => TicketStatus::pendingValues()])),
            'Waiting for authorization' => $this->card($visible()->where('status', $pending)->count(), route('tickets.index', ['status' => [$pending]])),
            'Work completed (awaiting closure)' => $this->card($visible()->where('status', $completed)->count(), route('tickets.index', ['status' => [$completed]])),
        ]);
    }

    public function custodian(): View
    {
        return $this->show('Property Custodian Dashboard', [
            'Inventory items' => $this->card(Inventory::count()),
            'Needs repair' => $this->card(Inventory::where('condition', 'needs_repair')->count()),
        ]);
    }

    public function president(Request $request): View
    {
        $user = $request->user();
        $visible = fn () => MaintenanceRequest::query()->visibleTo($user);
        $pending = TicketStatus::PendingAuthorization->value;
        $closed = TicketStatus::Closed->value;

        return $this->show('School President Dashboard', [
            'Waiting for your approval' => $this->card($visible()->awaitingAuthorization()->count(), $user->canAuthorize() ? route('approvals.index') : route('tickets.index', ['status' => [$pending]])),
            'Authorized by you' => $this->card($visible()->whereNotNull('president_approved_at')->count()),
            'Closed' => $this->card($visible()->where('status', $closed)->count(), route('tickets.history', ['status' => [$closed]])),
        ]);
    }

    /** One dashboard card: the number, and the pre-filtered ticket list it links to (if any). */
    private function card(int $count, ?string $url = null): array
    {
        return ['count' => $count, 'url' => $url];
    }

    private function show(string $title, array $cards): View
    {
        // Phase 15: the hero's "Last update" = the latest change on a ticket this person may see
        $lastUpdate = MaintenanceRequest::query()
            ->visibleTo(request()->user())
            ->orderByDesc('updated_at')
            ->first(['updated_at'])
            ?->updated_at;

        return view('dashboards.role', compact('title', 'cards', 'lastUpdate'));
    }
}
