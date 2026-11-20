<?php

namespace App\Http\Controllers;

use App\Http\Requests\Forms\StoreRequisitionSlipRequest;
use App\Models\Department;
use App\Models\RequisitionSlip;
use App\Models\Role;
use App\Models\User;
use App\Rules\AdvanceNotice;
use App\Services\FormNotifier;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Form 2: Requisition Slip (Form 1-08).
 * File it, follow the signatures (Noted > Recommending Approval > Approved), print the paper copy, close it.
 * The chain itself lives in RequisitionSlip::workflow() + HasApprovalWorkflow (same as Form 1).
 */
class RequisitionSlipController extends Controller
{
    private const PER_PAGE = 15;

    private const MAX_ITEMS = 30;

    public function create(Request $request): View
    {
        abort_unless(in_array($request->user()->role?->slug, config('school.form_roles', []), true), 403);

        return view('requisitions.create', [
            'types' => RequisitionSlip::TYPES,
            'departments' => Department::active()->orderBy('name')->get(),
            'defaultDepartment' => $request->user()->department_id,
            'earliest' => AdvanceNotice::earliest('requisition'),
            'maxItems' => self::MAX_ITEMS,
        ]);
    }

    public function store(StoreRequisitionSlipRequest $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role?->slug, config('school.form_roles', []), true), 403);

        $data = $request->validated();
        $types = array_values($data['request_types']);

        $form = DB::transaction(function () use ($data, $types, $request) {
            $form = RequisitionSlip::create([
                'requester_id' => $request->user()->id,
                'department_id' => $data['department_id'],
                'date_filed' => now()->toDateString(),
                'date_needed' => $data['date_needed'],
                'request_types' => $types,
                'other_type' => in_array('others', $types, true) ? ($data['other_type'] ?? null) : null,
                // "For check only": the payee only matters when Cash is ticked
                'payee_name' => in_array('cash', $types, true) ? ($data['payee_name'] ?? null) : null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $form->items()->create([
                    'quantity' => $item['quantity'],
                    'description' => $item['description'],
                    'account_charged' => $item['account_charged'] ?? null,
                    'amount' => $item['amount'],
                ]);
            }

            $form->recalculateTotal();

            return $form;
        });

        FormNotifier::submitted($form);   // alerts the first signatory (OIC / Dept. Head)

        return redirect()->route('requisitions.show', $form)
            ->with('status', "Sent! Your requisition slip {$form->reference_no} was received. The OIC/Dept. Head has been alerted to note it.");
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $waiting = $this->visible($user)
            ->whereIn('status', ['submitted', 'noted', 'recommended'])
            ->with(['requester', 'department'])
            ->orderBy('date_needed')
            ->limit(50)
            ->get()
            ->filter(fn (RequisitionSlip $f) => $this->canSignNext($user, $f))
            ->values();

        $forms = $this->visible($user)
            ->with(['requester', 'department'])
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return view('requisitions.index', compact('waiting', 'forms'));
    }

    public function show(Request $request, RequisitionSlip $requisitionSlip): View
    {
        $user = $request->user();
        $this->authorizeView($user, $requisitionSlip);

        $form = $requisitionSlip->load(['requester', 'department', 'items', 'approvals.signer']);

        return view('requisitions.show', [
            'form' => $form,
            'nextLine' => $form->nextApproval(),
            'canSign' => $this->canSignNext($user, $form),
            'canClose' => $this->canClose($user, $form),
        ]);
    }

    public function print(Request $request, RequisitionSlip $requisitionSlip): View
    {
        $this->authorizeView($request->user(), $requisitionSlip);

        return view('requisitions.print', [
            'form' => $requisitionSlip->load(['requester', 'department', 'items', 'approvals.signer']),
            'types' => RequisitionSlip::TYPES,
        ]);
    }

    public function sign(Request $request, RequisitionSlip $requisitionSlip): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $requisitionSlip);

        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        try {
            $line = $requisitionSlip->sign($user, $data['remarks'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        FormNotifier::signed($requisitionSlip, $user, $line, $data['remarks'] ?? null);

        return back()->with('status', 'Signed: '.$line->label);
    }

    public function reject(Request $request, RequisitionSlip $requisitionSlip): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $requisitionSlip);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $line = $requisitionSlip->reject($user, $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        FormNotifier::signed($requisitionSlip, $user, $line, $data['reason']);

        return back()->with('status', 'The requisition slip was disapproved.');
    }

    /** Close an approved slip (after the cash/supplies/materials were released). */
    public function close(Request $request, RequisitionSlip $requisitionSlip): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $requisitionSlip);
        abort_unless($this->canCloseRole($user), 403);

        try {
            $requisitionSlip->close();   // saving hook: needs the School President's approval first
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $requisitionSlip->reference_no.' was closed.');
    }

    // ---------------------------------------------------------------------------------------------

    /** Everyone sees their own slips. Finance, Custodian, President and System Admin see all; an OIC/Dept. Head sees the department's. */
    private function visible(User $user): Builder
    {
        $q = RequisitionSlip::query();

        if ($user->hasRole(Role::FINANCE, Role::CUSTODIAN, Role::PRESIDENT, Role::SYSTEM_ADMIN)) {
            return $q;
        }

        return $q->where(function (Builder $w) use ($user) {
            $w->where('requester_id', $user->id);

            if ($user->hasRole(Role::DEPARTMENT_HEAD, Role::HEAD) && $user->department_id) {
                $w->orWhere('department_id', $user->department_id);
            }
        });
    }

    private function authorizeView(User $user, RequisitionSlip $form): void
    {
        abort_unless($this->visible($user)->whereKey($form->getKey())->exists(), 404);
    }

    private function canCloseRole(User $user): bool
    {
        return $user->is_active && $user->hasRole(Role::FINANCE, Role::PRESIDENT, Role::SYSTEM_ADMIN);
    }

    private function canClose(User $user, RequisitionSlip $form): bool
    {
        return $this->canCloseRole($user) && $form->status === 'approved';
    }

    /** Is the pending signature line this user's to sign? (same rules as HasApprovalWorkflow::sign) */
    private function canSignNext(User $user, RequisitionSlip $form): bool
    {
        $line = $form->nextApproval();

        if (! $line || ! $user->is_active) {
            return false;
        }

        $oic = $line->role_slug === Role::DEPARTMENT_HEAD;
        $roles = $oic ? [Role::DEPARTMENT_HEAD, Role::HEAD] : [$line->role_slug];

        if (! $user->hasRole(...$roles)) {
            return false;
        }

        return ! $oic || (int) $user->department_id === (int) $form->department_id;
    }
}
