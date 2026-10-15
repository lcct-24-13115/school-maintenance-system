<?php

namespace App\Models\Concerns;

use App\Models\FormApproval;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * Signature workflow shared by the 3 official forms.
 *
 * Each model defines:
 *   const REF_PREFIX = 'FE';
 *   public static function workflow(): array   // ordered list of signature lines after the requester
 *      [ ['step' => 'endorsed', 'label' => 'Endorsed by (Office-In-Charge)', 'role' => 'department_head', 'status' => 'endorsed'], ... ]
 * The LAST step must be 'president'. A form can never be closed without it.
 */
trait HasApprovalWorkflow
{
    public static function bootHasApprovalWorkflow(): void
    {
        static::creating(function ($form) {
            $form->reference_no ??= $form->generateReferenceNo();
        });

        static::created(function ($form) {
            $form->seedApprovals();
        });

        static::saving(function ($form) {
            if ($form->isDirty('status') && $form->status === 'closed' && ($reason = $form->closeBlockReason())) {
                throw new DomainException($reason);
            }
        });
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(FormApproval::class, 'approvable')->orderBy('step_order');
    }

    public function generateReferenceNo(): string
    {
        $next = (static::withTrashed()->max('id') ?? 0) + 1;

        return sprintf('%s-%d-%05d', static::REF_PREFIX, now()->year, $next);
    }

    /** Line 0 = the requester (signed automatically when the form is filed). */
    protected function seedApprovals(): void
    {
        $this->approvals()->create([
            'step' => 'requested', 'step_order' => 0, 'label' => 'Requested by (Signature over Printed Name)',
            'role_slug' => $this->requester?->role?->slug ?? 'requester',
            'user_id' => $this->requester_id, 'status' => 'approved', 'signed_at' => now(),
        ]);

        foreach (static::workflow() as $i => $line) {
            $this->approvals()->create([
                'step' => $line['step'], 'step_order' => $i + 1,
                'label' => $line['label'], 'role_slug' => $line['role'],
            ]);
        }
    }

    public function nextApproval(): ?FormApproval
    {
        if ($this->status === 'rejected' || $this->status === 'closed') {
            return null;
        }

        return $this->approvals()->where('status', 'pending')->first();
    }

    public function isPresidentApproved(): bool
    {
        return $this->exists
            && $this->approvals()->where('step', 'president')->where('status', 'approved')->exists();
    }

    /** Sign the next line. Only the right role (and, for Dept. Head, the right department) may. */
    public function sign(User $user, ?string $remarks = null): FormApproval
    {
        $line = $this->assertCanSign($user);
        $def = collect(static::workflow())->firstWhere('step', $line->step);

        DB::transaction(function () use ($line, $user, $remarks, $def) {
            $line->update(['user_id' => $user->id, 'status' => 'approved', 'signed_at' => now(), 'remarks' => $remarks]);
            $this->update(['status' => $def['status']]);
        });

        return $line->refresh();
    }

    public function reject(User $user, ?string $remarks = null): FormApproval
    {
        $line = $this->assertCanSign($user);

        DB::transaction(function () use ($line, $user, $remarks) {
            $line->update(['user_id' => $user->id, 'status' => 'rejected', 'signed_at' => now(), 'remarks' => $remarks]);
            $this->update(['status' => 'rejected']);
        });

        return $line->refresh();
    }

    public function close(): void
    {
        $this->update(['status' => 'closed', 'closed_at' => now()]); // saving hook enforces the rules
    }

    /** Why this form can't be closed yet (null = it can). Models may override to add rules. */
    public function closeBlockReason(): ?string
    {
        return $this->isPresidentApproved() ? null : 'This form cannot be closed without the School President\'s approval.';
    }

    protected function assertCanSign(User $user): FormApproval
    {
        $line = $this->nextApproval() ?? throw new DomainException('This form has no pending signature.');

        if (! $user->is_active || ! $user->hasRole($line->role_slug)) {
            throw new DomainException('You are not allowed to sign this line ('.$line->label.').');
        }
        if ($line->role_slug === 'department_head' && $user->department_id !== $this->department_id) {
            throw new DomainException('Only the head of the requesting department may sign this line.');
        }

        return $line;
    }
}
