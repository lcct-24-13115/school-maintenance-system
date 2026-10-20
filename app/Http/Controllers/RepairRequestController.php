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
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Form 3: Request for Repairs & Improvement (FO-08) — requester side.
 * Since Phase 1 this form files a TICKET (requests table); the old repair_requests table is no longer used.
 */
class RepairRequestController extends Controller
{
    public function create(Request $request): View
    {
        Gate::authorize('create', MaintenanceRequest::class);

        $canFlag = $request->user()->can_do('flag_emergency');

        return view('repairs.create', [
            'canFlag' => $canFlag,
            'emergencyBypass' => $canFlag && config('school.emergency_bypasses_advance_notice'),
            'departments' => Department::orderBy('name')->get(),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(),
            'defaultDepartment' => $request->user()->department_id,
            'earliest' => AdvanceNotice::earliest('repair'),
        ]);
    }

    public function store(StoreRepairRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $asked = $data['priority'] === MaintenanceRequest::PRIORITY_EMERGENCY;

        // Only users with flag_emergency can really set the flag. Everyone else who ticks "Emergency"
        // files a ROUTINE ticket and the request for review is written to the history instead.
        $emergency = $asked && $request->user()->can_do('flag_emergency');
        $reviewAsked = $asked && ! $emergency;

        $ticket = TicketWorkflow::open($request->user(), [
            'department_id' => $data['department_id'],
            'category_id' => $data['category_id'],
            'maintenance_type' => 'repair',
            'location' => $data['location'],
            'description' => $data['job_description'],
            'reason' => $data['reason'],
            'needed_by' => $data['needed_by'] ?? null,
            'is_emergency' => $emergency,
            // the requester's reason doubles as the emergency reason
            'emergency_reason' => $emergency ? $data['reason'] : null,
            'emergency_review_reason' => $reviewAsked ? $data['reason'] : null,
        ], $request->file('photo'));

        FormNotifier::submitted($ticket);   // chime + pop-up for maintenance

        $message = "Sent! Your ticket {$ticket->ticket_no} was received and Maintenance has been alerted.";
        if ($reviewAsked) {
            $message .= ' It was filed as a routine ticket; your emergency request was passed to Maintenance for review.';
        }

        return redirect()->route('dashboard')->with('status', $message);
    }
}
