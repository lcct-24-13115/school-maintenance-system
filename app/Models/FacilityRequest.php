<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** FORM 1: Use of Facilities & Equipment */
class FacilityRequest extends Model
{
    use HasApprovalWorkflow, SoftDeletes;

    public const REF_PREFIX = 'FE';
    public const LABEL = 'Use of Facilities & Equipment';

    protected $table = 'facilities_equipment_requests';

    protected $fillable = [
        'reference_no', 'requester_id', 'department_id', 'date_filed', 'purpose', 'persons_count',
        'date_needed', 'time_needed', 'other_facility', 'status',
        'returned_by_name', 'returned_at', 'received_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['date_filed' => 'date', 'date_needed' => 'date', 'returned_at' => 'date', 'closed_at' => 'datetime'];
    }

    public static function workflow(): array
    {
        return [
            ['step' => 'endorsed', 'label' => 'Endorsed by (Office-In-Charge)', 'role' => 'department_head', 'status' => 'endorsed'],
            ['step' => 'noted', 'label' => 'Noted by (Property Custodian / Director, Campus Facilities & General Services)', 'role' => 'custodian', 'status' => 'noted'],
            ['step' => 'president', 'label' => 'Approved by (School President)', 'role' => 'president', 'status' => 'approved'],
        ];
    }

    /** Property Custodian records the "Returned by / Date Returned" box. */
    public function markReturned(User $custodian, string $returnedByName, $date = null): void
    {
        abort_unless($custodian->hasRole(Role::CUSTODIAN), 403);

        $this->update([
            'returned_by_name' => $returnedByName,
            'returned_at' => $date ?? now(),
            'received_by' => $custodian->id,
            'status' => 'returned',
        ]);
    }

    public function closeBlockReason(): ?string
    {
        if (! $this->isPresidentApproved()) {
            return 'This form cannot be closed without the School President\'s approval.';
        }

        return $this->returned_at ? null : 'Equipment/facility has not been marked as returned yet.';
    }

    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function facilities(): BelongsToMany { return $this->belongsToMany(Facility::class, 'fe_request_facility', 'fe_request_id', 'facility_id'); }
    public function items(): HasMany { return $this->hasMany(FacilityRequestItem::class, 'fe_request_id'); }
}
