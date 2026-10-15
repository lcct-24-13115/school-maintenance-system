<?php

namespace App\Http\Controllers;

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
            'My requests' => (clone $mine)->count(),
            'Waiting for review' => (clone $mine)->where('status', 'submitted')->count(),
            'In progress' => (clone $mine)->whereIn('status', ['under_review', 'queued', 'in_progress', 'for_finance', 'for_president_approval'])->count(),
            'Closed' => (clone $mine)->where('status', 'closed')->count(),
        ]);
    }

    public function maintenance(): View
    {
        return $this->show('Maintenance Admin Dashboard', [
            'New requests' => MaintenanceRequest::where('status', 'submitted')->count(),
            'Emergency (open)' => MaintenanceRequest::where('priority', 'emergency')->whereNotIn('status', ['completed', 'closed', 'rejected'])->count(),
            'In progress' => MaintenanceRequest::where('status', 'in_progress')->count(),
            'Completed' => MaintenanceRequest::where('status', 'completed')->count(),
        ]);
    }

    public function finance(): View
    {
        return $this->show('Finance Dashboard', [
            'For finance review' => MaintenanceRequest::where('status', 'for_finance')->count(),
            'Waiting for President' => MaintenanceRequest::where('status', 'for_president_approval')->count(),
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
            'Waiting for your approval' => MaintenanceRequest::where('status', 'for_president_approval')->count(),
            'Approved' => MaintenanceRequest::where('status', 'approved')->count(),
            'Closed' => MaintenanceRequest::where('status', 'closed')->count(),
        ]);
    }

    private function show(string $title, array $cards): View
    {
        return view('dashboards.role', compact('title', 'cards'));
    }
}
