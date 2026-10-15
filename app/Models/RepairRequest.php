<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalWorkflow;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** FORM 3: Request for Repairs & Improvement (Form FO-08) */
class RepairRequest extends Model
{
    use HasApprovalWorkflow, SoftDeletes;

    public const REF_PREFIX = 'RR';
    public const LABEL = 'Request for Repairs & Improvement';

    public const PRIORITY_FCFS = 'first_come_first_served';
    public const PRIORITY_EMERGENCY = 'emergency';

    protected $fillable = [
        'reference_no', 'requester_id', 'department_id', 'date_requested', 'needed_by', 'location',
        'job_description', 'reason', 'photo_path', 'priority', 'status', 'work_status',
        'assigned_to', 'accepted_at', 'completed_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'date_requested' => 'date', 'needed_by' => 'date', 'accepted_at' => 'datetime',
            'completed_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public static function workflow(): array
    {
        return [
            ['step' => 'noted', 'label' => 'Noted by (OIC / Unit Head)', 'role' => 'department_head', 'status' => 'noted'],
            ['step' => 'treasurer', 'label' => 'Approved by (Treasurer)', 'role' => 'treasurer', 'status' => 'treasurer_approved'],
            ['step' => 'president', 'label' => 'Approved by (President)', 'role' => 'president', 'status' => 'approved'],
        ];
    }

    /** Queue order: Emergency first, then First Come First Served. */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE WHEN priority = 'emergency' THEN 0 ELSE 1 END")
            ->orderBy('created_at');
    }

    public function isEmergency(): bool
    {
        return $this->priority === self::PRIORITY_EMERGENCY;
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /** [Accept Task] button */
    public function accept(User $staff): void
    {
        $this->assertMaintenance($staff);
        if ($this->work_status !== 'pending') {
            throw new DomainException('This task was already accepted.');
        }
        $this->update(['work_status' => 'accepted', 'assigned_to' => $staff->id, 'accepted_at' => now()]);
    }

    /** [Mark Completed] button (work done; the form itself still needs President approval to close). */
    public function markCompleted(User $staff): void
    {
        $this->assertMaintenance($staff);
        if ($this->work_status !== 'accepted') {
            throw new DomainException('Accept the task first.');
        }
        $this->update(['work_status' => 'completed', 'completed_at' => now()]);
    }

    private function assertMaintenance(User $staff): void
    {
        if (! $staff->hasRole(Role::MAINTENANCE_ADMIN)) {
            throw new DomainException('Only maintenance staff can do this.');
        }
    }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
