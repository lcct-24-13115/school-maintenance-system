<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Table is "requests"; model is named MaintenanceRequest to avoid
 * clashing with Illuminate\Http\Request.
 */
class MaintenanceRequest extends Model
{
    use SoftDeletes;

    protected $table = 'requests';

    public const PRIORITY_FCFS = 'first_come_first_served';
    public const PRIORITY_EMERGENCY = 'emergency';

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'reference_no', 'requester_id', 'department_id', 'category_id',
        'request_type', 'maintenance_type', 'location', 'description',
        'priority', 'status', 'assigned_to',
        'president_id', 'president_approved_at', 'president_remarks',
        'submitted_at', 'completed_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'president_approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->reference_no ??= self::generateReferenceNo();
            $request->submitted_at ??= now();
        });

        static::saving(function (self $request) {
            // Rule 1: Materials / Equipment are mutually exclusive.
            // The chosen category must belong to the chosen request type.
            if ($request->isDirty(['category_id', 'request_type'])) {
                $category = Category::find($request->category_id);
                if ($category && $category->type !== $request->request_type) {
                    throw new DomainException('Category does not match the request type (materials/equipment).');
                }
            }

            // Rule 2: no Completed/Closed without the School President's approval.
            if ($request->isDirty('status')
                && in_array($request->status, [self::STATUS_COMPLETED, self::STATUS_CLOSED], true)
                && (! $request->president_id || ! $request->president_approved_at)) {
                throw new DomainException('A request cannot be completed or closed without President approval.');
            }
        });
    }

    public static function generateReferenceNo(): string
    {
        $next = (static::withTrashed()->max('id') ?? 0) + 1;

        return sprintf('MR-%d-%05d', now()->year, $next);
    }

    /** Queue order: Emergency first, then First Come First Served by submission time. */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE WHEN priority = 'emergency' THEN 0 ELSE 1 END")
            ->orderBy('submitted_at');
    }

    public function isPresidentApproved(): bool
    {
        return $this->president_id !== null && $this->president_approved_at !== null;
    }

    /** Only a user with the President role may sign off. */
    public function approveByPresident(User $president, ?string $remarks = null): void
    {
        if (! $president->isPresident()) {
            throw new DomainException('Only the School President can approve this request.');
        }

        $from = $this->status;
        $this->update([
            'president_id' => $president->id,
            'president_approved_at' => now(),
            'president_remarks' => $remarks,
            'status' => self::STATUS_APPROVED,
        ]);
        $this->logStatus($from, self::STATUS_APPROVED, $president, $remarks);
    }

    public function transitionTo(string $status, ?User $by = null, ?string $note = null): void
    {
        $from = $this->status;
        $data = ['status' => $status];
        if ($status === self::STATUS_COMPLETED) {
            $data['completed_at'] = now();
        }
        if ($status === self::STATUS_CLOSED) {
            $data['closed_at'] = now();
        }
        $this->update($data); // saving hook blocks completed/closed without President approval
        $this->logStatus($from, $status, $by, $note);
    }

    protected function logStatus(?string $from, string $to, ?User $by, ?string $note): void
    {
        $this->statusLogs()->create([
            'user_id' => $by?->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
        ]);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function president(): BelongsTo
    {
        return $this->belongsTo(User::class, 'president_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class, 'request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RequestAttachment::class, 'request_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(RequestStatusLog::class, 'request_id');
    }

    public function expenditures(): HasMany
    {
        return $this->hasMany(ReceiptExpenditure::class, 'request_id');
    }
}
