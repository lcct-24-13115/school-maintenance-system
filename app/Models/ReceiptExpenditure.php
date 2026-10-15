<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReceiptExpenditure extends Model
{
    use SoftDeletes;

    protected $table = 'receipts_expenditures';

    protected $fillable = [
        'request_id', 'department_id', 'recorded_by', 'or_number',
        'supplier', 'purchase_date', 'amount', 'receipt_path', 'remarks',
    ];

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        // Charge the requesting department unless Finance picks another one.
        static::creating(function (self $row) {
            if (! $row->department_id && $row->request_id) {
                $row->department_id = MaintenanceRequest::find($row->request_id)?->department_id;
            }
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
