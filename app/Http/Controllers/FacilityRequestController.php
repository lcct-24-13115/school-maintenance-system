<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forms\StoreFacilityRequestRequest;
use App\Models\Department;
use App\Models\Facility;
use App\Models\FacilityRequest;
use App\Rules\AdvanceNotice;
use App\Services\FormNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Form 1: Use of Facilities & Equipment - requester side (Phase 1: the form page + saving it).
 * The request is signed Endorsed > Noted > Approved by the chain in FacilityRequest::workflow().
 */
class FacilityRequestController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless(in_array($request->user()->role?->slug, config('school.filing_roles', []), true), 403);

        $facilities = Facility::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);

        return view('facilities.create', [
            'facilities' => $facilities,
            'othersId' => $facilities->firstWhere('name', 'Others')?->id,
            'departments' => Department::active()->orderBy('name')->get(),
            'defaultDepartment' => $request->user()->department_id,
            'earliest' => AdvanceNotice::earliest('facility'),
            'maxItems' => StoreFacilityRequestRequest::MAX_ITEMS,
        ]);
    }

    public function store(StoreFacilityRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $othersTicked = Facility::where('name', 'Others')->whereIn('id', $data['facility_ids'])->exists();

        $form = DB::transaction(function () use ($data, $request, $othersTicked) {
            $form = FacilityRequest::create([
                'requester_id' => $request->user()->id,   // the Person Responsible
                'department_id' => $data['department_id'],
                'date_filed' => now()->toDateString(),
                'purpose' => $data['purpose'],
                'persons_count' => $data['persons_count'],
                'date_needed' => $data['date_needed'],
                'time_needed' => $data['time_needed'],
                'other_facility' => $othersTicked ? $data['other_facility'] : null,
            ]);

            $form->facilities()->sync($data['facility_ids']);

            foreach ($data['items'] ?? [] as $item) {
                $form->items()->create([
                    'quantity' => $item['quantity'],
                    'description' => $item['description'],
                ]);
            }

            return $form;
        });

        FormNotifier::submitted($form);   // alerts the first signatory (Office-In-Charge)

        return redirect()->route('facilities.show', $form)
            ->with('status', "Sent! Your request {$form->reference_no} was received. The Office-In-Charge has been alerted to endorse it.");
    }
}