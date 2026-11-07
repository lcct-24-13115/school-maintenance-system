<?php

namespace App\Console\Commands;

use App\Models\MaintenanceRequest;
use App\Services\FormNotifier;
use App\Services\TicketWorkflow;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 12: escalate open tickets that are past their target date (due_at). Scheduled hourly in routes/console.php.
 *
 * A ticket qualifies when due_at is in the past AND its status is one of TicketWorkflow::escalatableStatuses()
 * (Submitted ... In Progress, Reopened). Waiting for User is skipped (its SLA clock is paused), and so are
 * Escalated (already done, so no second alert), Work Completed, Closed, Disapproved and Cancelled.
 * Each escalated ticket gets a history line without a user ("Auto-escalated ...") and alerts the President
 * and the Maintenance Admin.
 *
 *   php artisan tickets:escalate --dry-run    only lists what would be escalated
 */
class EscalateTickets extends Command
{
    protected $signature = 'tickets:escalate {--dry-run : List the tickets that would be escalated without escalating them}';

    protected $description = 'Escalate open tickets that are past their target date to the President and the Maintenance Admin';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $count = 0;

        MaintenanceRequest::query()
            ->whereNotNull('due_at')
            ->whereNull('pm_due_date')   // PM tickets are handled by pm:notify: their status must stay workable
            ->where('due_at', '<', now())
            ->whereIn('status', TicketWorkflow::escalatableValues())
            ->chunkById(100, function ($tickets) use ($dry, &$count) {
                foreach ($tickets as $ticket) {
                    if ($dry) {
                        $this->line("Would escalate {$ticket->ticket_no} (was due {$ticket->due_at->toDateTimeString()}).");
                        $count++;

                        continue;
                    }

                    try {
                        $escalated = TicketWorkflow::autoEscalate($ticket);
                        FormNotifier::escalated($escalated);   // never throws
                        $this->line("Escalated {$ticket->ticket_no}.");
                        $count++;
                    } catch (Throwable $e) {
                        report($e);   // one bad ticket must not stop the others (also a ticket someone just changed)
                        $this->warn("Skipped {$ticket->ticket_no}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(($dry ? 'Would escalate ' : 'Escalated ').$count.' ticket(s).');

        return self::SUCCESS;
    }
}
