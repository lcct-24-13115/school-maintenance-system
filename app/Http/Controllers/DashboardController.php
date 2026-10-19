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
        $mine = $request->user()->requests();

        return $this->show('Requester Dashboard', [
            'My tickets' => (clone $mine)->count(),
            'Waiting for authorization' => (clone $mine)->whereIn('status', [TicketStatus::Submitted->value, TicketStatus::PendingAuthorization->value])->count(),
            'In progress' => (clone $mine)->whereIn('status', [TicketStatus::Approved->value, TicketStatus::Assigned->value, TicketStatus::InProgress->value, TicketStatus::WorkCompleted->value])->count(),
            'Closed' => (clone $mine)->where('status', TicketStatus::Closed->value)->count(),
        ]);
    }

    /** Maintenance staff land straight on the big-button card board. */
    public function maintenance(): RedirectResponse
    {
        return redirect()->route('maintenance.board');
    }

    public function finance(): View
    {
        return $this->show('Finance Dashboard', [
            'Pending tickets' => MaintenanceRequest::pending()->count(),
            'Waiting for authorization' => MaintenanceRequest::where('status', TicketStatus::PendingAuthorization->value)->count(),
            'Work completed (awaiting closure)' => MaintenanceRequest::where('status', TicketStatus::WorkCompleted->value)->count(),
        ]);
    }

    public function custodian(): View
    {
        return $this->show('Property Custodian Dashboard', [
            'Inventory items' => Inventory::count(),
            'Needs repair' => Inventory::where('condition', 'needs_repair')->count(),
        ]);
    }

    public function president(): View
    {
        return $this->show('School President Dashboard', [
            'Waiting for your approval' => MaintenanceRequest::where('status', TicketStatus::PendingAuthorization->value)->count(),
            'Authorized by you' => MaintenanceRequest::whereNotNull('president_approved_at')->count(),
            'Closed' => MaintenanceRequest::where('status', TicketStatus::Closed->value)->count(),
        ]);
    }

    private function show(string $title, array $cards): View
    {
        return view('dashboards.role', compact('title', 'cards'));
    }
}
