<?php

namespace App\Providers;

use App\Models\Faq;
use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Models\User;
use App\Observers\FaqObserver;
use App\Observers\UserObserver;
use App\Policies\TicketPolicy;
use App\Services\AuditLogger;
use App\Services\EmailLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
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

        // Help & FAQ Phase 5C: any change to an entry clears the cached Help lists.
        Faq::observe(FaqObserver::class);

        // Every {faq} in a URL: a person who may not manage the FAQ gets 403 on the admin routes BEFORE the entry is
        // looked up, so a hidden entry and an id that does not exist look the same to them (no way to probe ids).
        Route::bind('faq', function (string $value) {
            if (request()->routeIs('admin.faq.*')) {
                abort_unless(request()->user()?->canManageFaq(), 403, 'You do not have access to this page.');
            }

            return Faq::query()->where('id', $value)->firstOrFail();
        });
        $this->registerAuditListeners();
        $this->registerEmailLogListeners();

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
     * E-mail log: every e-mail the mailer handles is recorded, with its real result.
     * EmailLogger never throws, so a logging problem can never stop an e-mail.
     * A queued e-mail that throws is caught here (JobExceptionOccurred / JobFailed); one sent directly is caught by
     * the exception reporter in bootstrap/app.php.
     */
    private function registerEmailLogListeners(): void
    {
        Event::listen(MessageSending::class, fn (MessageSending $e) => EmailLogger::sending($e));
        Event::listen(MessageSent::class, fn (MessageSent $e) => EmailLogger::sent($e));
        Event::listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => EmailLogger::failed($e->exception));
        Event::listen(JobFailed::class, fn (JobFailed $e) => EmailLogger::failed($e->exception));
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
