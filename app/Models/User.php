<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
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

    public function isPresident(): bool
    {
        return $this->hasRole(Role::PRESIDENT);
    }

    /** Route name of this user's dashboard (used after login). */
    public function dashboardRoute(): string
    {
        return match ($this->role?->slug) {
            Role::MAINTENANCE_ADMIN => 'maintenance.dashboard',
            Role::FINANCE => 'finance.dashboard',
            Role::CUSTODIAN => 'custodian.dashboard',
            Role::PRESIDENT => 'president.dashboard',
            default => 'requester.dashboard',
        };
    }
}
