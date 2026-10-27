<?php

namespace App\Observers;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Audit trail for accounts (Merge Phase M3). There is no user-management screen yet, so accounts are watched
 * at the model: registration, seeders and a future admin screen are all covered by this one observer.
 *
 * Only field NAMES are listed for ordinary edits. Password and remember_token are never inspected or logged
 * (a password reset has its own listener in AppServiceProvider).
 */
class UserObserver
{
    /** changed column => name shown in the audit row. Anything not listed here is ignored. */
    private const TRACKED = [
        'name' => 'name',
        'email' => 'email',
        'department_id' => 'department',
        'employee_no' => 'employee_no',
    ];

    public function created(User $user): void
    {
        $role = Role::query()->whereKey($user->role_id)->value('slug');

        AuditLogger::log(
            AuditLogger::USER_CREATED,
            "Account created: {$user->name} ({$user->email})".($role ? ", role {$role}" : ''),
            $user,
            meta: $role ? ['role' => $role] : null,
        );
    }

    public function updated(User $user): void
    {
        // In "updated" the new values are in getChanges() and getOriginal() still holds the old ones.
        $changes = $user->getChanges();

        if (array_key_exists('is_active', $changes)) {
            $active = (bool) $user->is_active;

            AuditLogger::log(
                $active ? AuditLogger::USER_REACTIVATED : AuditLogger::USER_DEACTIVATED,
                ($active ? 'Account reactivated: ' : 'Account deactivated: ')."{$user->name} ({$user->email})",
                $user,
            );
        }

        if (array_key_exists('role_id', $changes)) {
            $from = Role::query()->whereKey($user->getOriginal('role_id'))->value('slug');
            $to = Role::query()->whereKey($changes['role_id'])->value('slug');

            AuditLogger::log(
                AuditLogger::ROLE_CHANGED,
                'Role changed for '.$user->name.': '.($from ?? 'none').' -> '.($to ?? 'none'),
                $user,
                meta: ['from_role' => $from, 'to_role' => $to],
            );
        }

        $fields = array_values(array_intersect_key(self::TRACKED, $changes));

        if ($fields !== []) {
            AuditLogger::log(
                AuditLogger::USER_UPDATED,
                "Account updated: {$user->name}. Changed: ".implode(', ', $fields),
                $user,
                meta: ['fields' => $fields],
            );
        }
    }
}
