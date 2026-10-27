<?php

namespace App\Providers;

use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\TicketPolicy;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Audit trail (Merge Phase M3): account changes through an observer, sign-in events through listeners.
        User::observe(UserObserver::class);
        $this->registerAuditListeners();

        // The model is MaintenanceRequest but the policy is named for what it is: the ticket.
        Gate::policy(MaintenanceRequest::class, TicketPolicy::class);

        // Every {ticket} in a URL is looked up through the visibility scope, so a ticket the user may not
        // see is a 404 (never 403) on EVERY route: its existence is not leaked.
        Route::bind('ticket', function (string $value) {
            $user = request()->user();

            abort_if($user === null, 404);

            return MaintenanceRequest::query()->visibleTo($user)->where('id', $value)->firstOrFail();
        });

        // Same idea for files: an attachment is found only if its TICKET is visible to the user (hidden = 404).
        Route::bind('attachment', function (string $value) {
            $user = request()->user();

            abort_if($user === null, 404);

            return RequestAttachment::query()
                ->whereKey($value)
                ->whereHas('request', fn ($q) => $q->visibleTo($user))
                ->with('request')
                ->firstOrFail();
        });
    }

    /**
     * Auth events -> audit_logs. AuditLogger never throws, so a logging problem can never block a sign-in.
     * Passwords are never read: a failed sign-in records only the attempted e-mail address (cut to 100 characters).
     */
    private function registerAuditListeners(): void
    {
        $user = fn ($u) => $u instanceof User ? $u : null;

        Event::listen(Login::class, fn (Login $e) => AuditLogger::log(AuditLogger::LOGIN, 'Signed in', $user($e->user), $user($e->user)));

        Event::listen(Logout::class, fn (Logout $e) => AuditLogger::log(AuditLogger::LOGOUT, 'Signed out', $user($e->user), $user($e->user)));

        Event::listen(Failed::class, function (Failed $e) {
            $email = mb_substr(trim((string) ($e->credentials['email'] ?? '')), 0, 100);

            // user_id stays null on purpose: the attempt is not proven to be that person.
            AuditLogger::log(
                AuditLogger::LOGIN_FAILED,
                'Failed sign-in attempt for '.($email !== '' ? $email : '(no e-mail given)'),
                meta: ['email' => $email],
            );
        });

        Event::listen(Registered::class, fn (Registered $e) => AuditLogger::log(AuditLogger::REGISTERED, 'New account registered', $user($e->user), $user($e->user)));

        Event::listen(PasswordReset::class, fn (PasswordReset $e) => AuditLogger::log(AuditLogger::PASSWORD_RESET, 'Password reset completed', $user($e->user), $user($e->user)));
    }
}
