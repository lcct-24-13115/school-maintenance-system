<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Account approval (registration): pending -> approved | rejected. Accounts made by an admin or a seeder are approved. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'role_id', 'department_id', 'employee_no',
        'name', 'email', 'password', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    /** May this account sign in as far as approval goes? (is_active is a separate switch.) */
    public function isApproved(): bool
    {
        return ($this->approval_status ?? self::STATUS_APPROVED) === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->approval_status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === self::STATUS_REJECTED;
    }

    /** Registrations still waiting for an admin. */
    public function scopePending($query)
    {
        return $query->where('approval_status', self::STATUS_PENDING);
    }

    /** First letter of the name, shown when there is no profile photo. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim((string) $this->name), 0, 1)) ?: '?';
    }

    /** URL of the profile photo (authorized route, private storage) or null. ?v= busts the cache on a new photo. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? route('avatars.show', ['user' => $this->getKey(), 'v' => substr(md5($this->avatar_path), 0, 8)])
            : null;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Requests this user submitted. */
    public function requests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'requester_id');
    }

    /** Requests assigned to this user (Maintenance Admin queue). */
    public function assignedRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'assigned_to');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'author_id');
    }

    public function hasRole(string ...$slugs): bool
    {
        return in_array($this->role?->slug, $slugs, true);
    }

    /**
     * Does this user's role have the capability? Reads config/permissions.php (role slug => list).
     * Inactive accounts can do nothing.
     */
    public function can_do(string $capability): bool
    {
        $slug = $this->role?->slug;

        if (! $this->is_active || $slug === null) {
            return false;
        }

        return in_array($capability, (array) config("permissions.{$slug}", []), true);
    }

    /** Department-level visibility (e.g. Department Head). Configurable: school.department_visibility_roles. */
    public function seesOwnDepartment(): bool
    {
        return $this->department_id !== null
            && $this->hasRole(...(array) config('school.department_visibility_roles', []));
    }

    public function isPresident(): bool
    {
        return $this->hasRole(Role::PRESIDENT);
    }

    /** May this user approve / disapprove tickets? Configurable: school.workflow.authorizers. */
    public function canAuthorize(): bool
    {
        return $this->is_active && $this->hasRole(...(array) config('school.workflow.authorizers', []));
    }

    /**
     * PM Scheduler (E2): may this user LOOK at PM schedules, equipment, their history and the maintenance calendar?
     * manage_pm_schedules includes it. Changing anything still needs manage_pm_schedules.
     */
    public function canViewPm(): bool
    {
        return $this->can_do('manage_pm_schedules') || $this->can_do('view_pm_schedules');
    }

    /** PM Scheduler (E3): may this user open the PM compliance report? manage_pm_schedules includes it. */
    public function canViewPmReports(): bool
    {
        return $this->can_do('manage_pm_schedules') || $this->can_do('view_pm_reports');
    }

    /** Help & FAQ (Phase 1): may this user add, edit, order, hide and delete the FAQ entries? (Everyone may READ the ones meant for them.) */
    public function canManageFaq(): bool
    {
        return $this->can_do('manage_faq');
    }

    /** Route name of this user's dashboard (used after login). */
    public function dashboardRoute(): string
    {
        return match ($this->role?->slug) {
            Role::MAINTENANCE_ADMIN => 'maintenance.dashboard',
            Role::FINANCE => 'finance.dashboard',
            Role::CUSTODIAN => 'custodian.dashboard',
            Role::PRESIDENT => 'president.dashboard',
            // Dept. Heads and the Treasurer use the requester dashboard until their approvals inbox is built
            default => 'requester.dashboard',
        };
    }
}
