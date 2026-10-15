<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forms\StoreRepairRequestRequest;
use App\Models\Department;
use App\Models\RepairRequest;
use App\Rules\AdvanceNotice;
use App\Services\FormNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Form 3: Request for Repairs & Improvement (FO-08) — requester side */
class RepairRequestController extends Controller
{
    public function create(Request $request): View
    {
        return view('repairs.create', [
            'departments' => Department::orderBy('name')->get(),
            'defaultDepartment' => $request->user()->department_id,
            'earliest' => AdvanceNotice::earliest('repair'),
        ]);
    }

    public function store(StoreRepairRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $repair = RepairRequest::create([
            'requester_id' => $request->user()->id,
            'department_id' => $data['department_id'],
            'date_requested' => today(),
            'needed_by' => $data['needed_by'] ?? null,
            'location' => $data['location'],
            'job_description' => $data['job_description'],
            'reason' => $data['reason'],
            'priority' => $data['priority'],
            'photo_path' => $request->file('photo')?->store('repair-photos', 'public'),
        ]);

        FormNotifier::submitted($repair);   // chime + pop-up for maintenance, alert for the Dept. Head

        return redirect()->route('dashboard')
            ->with('status', "Sent! Your request {$repair->reference_no} was received and Maintenance has been alerted.");
    }
}
