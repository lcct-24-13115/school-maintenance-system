<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\FacilityRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\FormNotifier;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Form 1: Use of Facilities & Equipment - Phase 2 (list, view, signatures, Returned box, printable copy).
 * The chain itself (Endorsed > Noted > Approved) lives in FacilityRequest::workflow() + HasApprovalWorkflow.
 */
class FacilityRequestFlowController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $user = $request->user();

        // requests where it is THIS user's turn to sign
        $waiting = $this->visible($user)
            ->whereIn('status', ['submitted', 'endorsed', 'noted'])
            ->with(['requester', 'department'])
            ->orderBy('date_needed')
            ->limit(50)
            ->get()
            ->filter(fn (FacilityRequest $f) => $this->canSignNext($user, $f))
            ->values();

        // approved, but the equipment / facility has not been marked as returned yet
        $toReturn = ($user->is_active && $user->hasRole(Role::CUSTODIAN))
            ? $this->visible($user)->where('status', 'approved')->whereNull('returned_at')
                ->with(['requester', 'department'])->orderBy('date_needed')->get()
            : collect();

        $forms = $this->visible($user)
            ->with(['requester', 'department'])
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return view('facilities.index', compact('waiting', 'toReturn', 'forms'));
    }

    public function show(Request $request, FacilityRequest $facilityRequest): View
    {
        $user = $request->user();
        $this->authorizeView($user, $facilityRequest);

        $form = $facilityRequest->load(['requester', 'department', 'facilities', 'items', 'approvals.signer', 'receiver']);

        // Phase 3: the Property Custodian (and the President / System Admin) are warned when another open request
        // wants the same facility on the same date. A warning only: nothing is blocked. "Others" is free text, so it is not compared.
        $conflicts = collect();
        if ($user->hasRole(Role::CUSTODIAN, Role::PRESIDENT, Role::SYSTEM_ADMIN) && in_array($form->status, ['submitted', 'endorsed', 'noted', 'approved'], true)) {
            $mine = $form->facilities->where('name', '!=', 'Others')->pluck('id');

            if ($mine->isNotEmpty()) {
                $conflicts = FacilityRequest::query()
                    ->where('id', '!=', $form->id)
                    ->whereDate('date_needed', $form->date_needed)
                    ->whereIn('status', ['submitted', 'endorsed', 'noted', 'approved'])
                    ->whereHas('facilities', fn ($q) => $q->whereIn('facilities.id', $mine))
                    ->with(['requester', 'facilities'])
                    ->orderBy('time_needed')
                    ->get();
            }
        }
        return view('facilities.show', [
            'form' => $form,
            'conflicts' => $conflicts, 'nextLine' => $form->nextApproval(),
            'canSign' => $this->canSignNext($user, $form),
            'canReturn' => $user->is_active && $user->hasRole(Role::CUSTODIAN)
                && $form->status === 'approved' && $form->returned_at === null,
        ]);
    }

    public function print(Request $request, FacilityRequest $facilityRequest): View
    {
        $this->authorizeView($request->user(), $facilityRequest);

        $form = $facilityRequest->load(['requester', 'department', 'facilities', 'items', 'approvals.signer']);
        $ticked = $form->facilities->pluck('id')->all();

        return view('facilities.print', [
            'form' => $form,
            'allFacilities' => Facility::orderBy('sort_order')->get()
                ->filter(fn (Facility $f) => $f->is_active || in_array($f->id, $ticked, true))
                ->values(),
            'ticked' => $ticked,
        ]);
    }

    /** Sign the next line (Endorsed, Noted or Approved). */
    public function sign(Request $request, FacilityRequest $facilityRequest): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $facilityRequest);

        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        try {
            $line = $facilityRequest->sign($user, $data['remarks'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        FormNotifier::signed($facilityRequest, $user, $line, $data['remarks'] ?? null);

        return back()->with('status', 'Signed: '.$line->label);
    }

    /** Disapprove at the line that is waiting for this user. A reason is required. */
    public function reject(Request $request, FacilityRequest $facilityRequest): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $facilityRequest);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $line = $facilityRequest->reject($user, $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        FormNotifier::signed($facilityRequest, $user, $line, $data['reason']);

        return back()->with('status', 'The request was disapproved.');
    }

    /** The Property Custodian fills in the "Returned by / Date Returned" box. */
    public function returned(Request $request, FacilityRequest $facilityRequest): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $facilityRequest);
        abort_unless($user->is_active && $user->hasRole(Role::CUSTODIAN), 403);

        if ($facilityRequest->status !== 'approved' || $facilityRequest->returned_at !== null) {
            return back()->with('error', 'Only an approved request that is not yet marked as returned can be recorded here.');
        }

        $data = $request->validate([
            'returned_by_name' => ['required', 'string', 'max:255'],
            'returned_at' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $facilityRequest->markReturned($user, $data['returned_by_name'], $data['returned_at']);

        if (method_exists(FormNotifier::class, 'returned')) {
            FormNotifier::returned($facilityRequest, $user);
        }

        return back()->with('status', 'Recorded: returned by '.$data['returned_by_name'].'.');
    }

    // ---------------------------------------------------------------------------------------------

    /** Everyone sees their own requests. Custodian, President and System Admin see all; an OIC (Department Head / Head) sees the department's. Maintenance does not see other people's. */
    private function visible(User $user): Builder
    {
        $q = FacilityRequest::query();

        if ($user->hasRole(Role::CUSTODIAN, Role::PRESIDENT, Role::SYSTEM_ADMIN)) {
            return $q;
        }

        return $q->where(function (Builder $w) use ($user) {
            $w->where('requester_id', $user->id);

            if ($user->hasRole(Role::DEPARTMENT_HEAD, Role::HEAD) && $user->department_id) {
                $w->orWhere('department_id', $user->department_id);
            }
        });
    }

    private function authorizeView(User $user, FacilityRequest $form): void
    {
        abort_unless($this->visible($user)->whereKey($form->getKey())->exists(), 404);
    }

    /** Is the pending signature line this user's to sign? (same rules as HasApprovalWorkflow::sign) */
    private function canSignNext(User $user, FacilityRequest $form): bool
    {
        $line = $form->nextApproval();

        if (! $line || ! $user->is_active) {
            return false;
        }

        $oic = $line->role_slug === Role::DEPARTMENT_HEAD;   // Office-In-Charge = the department's head
        $roles = $oic ? [Role::DEPARTMENT_HEAD, Role::HEAD] : [$line->role_slug];

        if (! $user->hasRole(...$roles)) {
            return false;
        }

        return ! $oic || (int) $user->department_id === (int) $form->department_id;
    }
}