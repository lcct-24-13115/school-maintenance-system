<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forms\StoreRepairRequestRequest;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Rules\AdvanceNotice;
use App\Services\FormNotifier;
use App\Services\TicketWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Form 3: Request for Repairs & Improvement (FO-08) — requester side.
 * Since Phase 1 this form files a TICKET (requests table); the old repair_requests table is no longer used.
 */
class RepairRequestController extends Controller
{
    public function create(Request $request): View
    {
        return view('repairs.create', [
            'departments' => Department::orderBy('name')->get(),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'defaultDepartment' => $request->user()->department_id,
            'earliest' => AdvanceNotice::earliest('repair'),
        ]);
    }

    public function store(StoreRepairRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $emergency = $data['priority'] === MaintenanceRequest::PRIORITY_EMERGENCY;

        $ticket = TicketWorkflow::open($request->user(), [
            'department_id' => $data['department_id'],
            'category_id' => $data['category_id'],
            'maintenance_type' => 'repair',
            'location' => $data['location'],
            'description' => $data['job_description'],
            'reason' => $data['reason'],
            'needed_by' => $data['needed_by'] ?? null,
            'is_emergency' => $emergency,
            // the requester's reason doubles as the emergency reason; Phase 2 decides who may flag
            'emergency_reason' => $emergency ? $data['reason'] : null,
        ], $request->file('photo'));

        FormNotifier::submitted($ticket);   // chime + pop-up for maintenance

        return redirect()->route('dashboard')
            ->with('status', "Sent! Your ticket {$ticket->ticket_no} was received and Maintenance has been alerted.");
    }
}
