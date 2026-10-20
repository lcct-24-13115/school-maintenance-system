<?php

namespace App\Providers;

use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Policies\TicketPolicy;
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
}
