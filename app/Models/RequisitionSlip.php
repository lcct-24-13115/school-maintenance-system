<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** FORM 2: Requisition Slip (Form 1-08) */
class RequisitionSlip extends Model
{
    use HasApprovalWorkflow, SoftDeletes;

    public const REF_PREFIX = 'RS';

    public const LABEL = 'Requisition Slip';

    public const TYPES = [
        'cash' => 'Cash',
        'supplies' => 'Supplies',
        'material_equipment' => 'Material/Equipment',
        'repair' => 'Repair',
        'others' => 'Others',
    ];

    protected $fillable = [
        'reference_no', 'requester_id', 'department_id', 'date_filed', 'date_needed',
        'request_types', 'other_type', 'payee_name', 'total_amount', 'remarks', 'status', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'date_filed' => 'date', 'date_needed' => 'date', 'closed_at' => 'datetime',
            'request_types' => 'array', 'total_amount' => 'decimal:2',
        ];
    }

    public static function workflow(): array
    {
        return [
            ['step' => 'noted', 'label' => 'Noted by (OIC/Dept. Head)', 'role' => 'department_head', 'status' => 'noted'],
            ['step' => 'recommended', 'label' => 'Recommending Approval by (Director, Finance & Accounting)', 'role' => 'finance', 'status' => 'recommended'],
            ['step' => 'president', 'label' => 'Approved by (School President)', 'role' => 'president', 'status' => 'approved'],
        ];
    }

    public function recalculateTotal(): void
    {
        $this->update(['total_amount' => $this->items()->sum('amount')]);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionSlipItem::class);
    }
}
