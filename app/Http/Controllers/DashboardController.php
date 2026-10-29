<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Inventory;
use App\Models\MaintenanceRequest;
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
            'My tickets' => $this->card((clone $mine)->count(), $own()),
            'Waiting for authorization' => $this->card((clone $mine)->whereIn('status', $waiting)->count(), $own($waiting)),
            'In progress' => $this->card((clone $mine)->whereIn('status', $active)->count(), $own($active)),
            'Closed' => $this->card((clone $mine)->where('status', TicketStatus::Closed->value)->count(), $own([TicketStatus::Closed->value])),
        ]);
    }

    /** Maintenance staff land straight on the big-button card board. */
    public function maintenance(): RedirectResponse
    {
        return redirect()->route('maintenance.board');
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
            'Closed' => $this->card($visible()->where('status', $closed)->count(), route('tickets.index', ['status' => [$closed]])),
        ]);
    }

    /** One dashboard card: the number, and the pre-filtered ticket list it links to (if any). */
    private function card(int $count, ?string $url = null): array
    {
        return ['count' => $count, 'url' => $url];
    }

    private function show(string $title, array $cards): View
    {
        return view('dashboards.role', compact('title', 'cards'));
    }
}
