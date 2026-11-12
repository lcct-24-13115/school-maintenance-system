<?php

namespace App\Services;

use App\Mail\AccountMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Account approval e-mails. Queued, like the ticket e-mails (a queue worker must run unless QUEUE_CONNECTION=sync).
 *
 *   pending($user)            a new registration is waiting: every active Maintenance Admin is told
 *   decided($user, $approved) the person is told the result (a rejection carries the admin's reason, if any)
 *
 * It never throws: a broken e-mail can never stop a registration or an approval. One switch turns all of it off:
 * ACCOUNT_EMAILS=false (config school.registration.account_emails). Every e-mail also shows in the E-mail log.
 */
class AccountMailer
{
    public static function enabled(): bool
    {
        return (bool) config('school.registration.account_emails', true);
    }

    /** A new registration is waiting for approval. */
    public static function pending(User $new): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $admins = User::query()
                ->where('is_active', true)
                ->where('approval_status', User::STATUS_APPROVED)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::MAINTENANCE_ADMIN))
                ->get();

            foreach ($admins as $admin) {
                self::queue($admin, 'account_pending', [
                    'subject' => 'New account waiting for approval',
                    'headline' => 'New account waiting for approval',
                    'intro' => "{$new->name} registered and needs your approval before they can sign in.",
                    'name' => $admin->name,
                    'details' => array_filter([
                        'Name' => $new->name,
                        'Email' => $new->email,
                        'Department' => $new->department?->name,
                    ], fn ($v) => filled($v)),
                    'reason' => null,
                    'url' => route('admin.users.index'),
                    'button' => 'Review accounts',
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The decision, sent to the person who registered. */
    public static function decided(User $user, bool $approved, ?string $reason = null): void
    {
        if (! self::enabled() || blank($user->email)) {
            return;
        }

        try {
            self::queue($user, $approved ? 'account_approved' : 'account_rejected', $approved ? [
                'subject' => 'Your account was approved',
                'headline' => 'Your account was approved',
                'intro' => 'A Maintenance Admin approved your account. You can sign in now.',
                'name' => $user->name,
                'details' => [],
                'reason' => null,
                'url' => route('login'),
                'button' => 'Sign in',
            ] : [
                'subject' => 'Your registration was not approved',
                'headline' => 'Your registration was not approved',
                'intro' => 'A Maintenance Admin did not approve your registration. Please contact the Maintenance Office if you think this is a mistake.',
                'name' => $user->name,
                'details' => [],
                'reason' => filled($reason) ? $reason : null,
                'url' => null,
                'button' => null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function queue(User $to, string $event, array $data): void
    {
        try {
            Mail::to($to)->queue(new AccountMail($event, $data));
        } catch (Throwable $e) {
            report($e);   // one bad address must not stop the others
        }
    }
}
