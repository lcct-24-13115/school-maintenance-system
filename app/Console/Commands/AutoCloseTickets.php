<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\MaintenanceRequest;
use App\Models\Setting;
use App\Services\TicketWorkflow;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 9: close tickets that have been Waiting for User for settings.autoclose_waiting_days (default 7) days.
 * Scheduled hourly in routes/console.php. "Waited" counts from the history line that entered Waiting for User,
 * so an answer and a second question start a fresh wait. A ticket becomes Cancelled with a history line saying why.
 *
 *   php artisan tickets:autoclose --dry-run    only lists what would be closed
 */
class AutoCloseTickets extends Command
{
    protected $signature = 'tickets:autoclose {--dry-run : List the tickets that would be closed without closing them}';

    protected $description = 'Close tickets that have waited for the requester longer than the auto-close setting';

    public function handle(): int
    {
        $days = Setting::int('autoclose_waiting_days');

        if ($days <= 0) {
            $this->info('Auto-close is turned off (autoclose_waiting_days is 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');
        $closed = 0;

        MaintenanceRequest::query()
            ->where('status', TicketStatus::WaitingForUser->value)
            ->chunkById(100, function ($tickets) use ($cutoff, $dry, $days, &$closed) {
                foreach ($tickets as $ticket) {
                    $since = TicketWorkflow::waitingSince($ticket);

                    if ($since === null || $since->greaterThan($cutoff)) {
                        continue;   // not waiting long enough yet
                    }

                    if ($dry) {
                        $this->line("Would close {$ticket->ticket_no} (waiting since {$since->toDateTimeString()}).");
                        $closed++;

                        continue;
                    }

                    try {
                        TicketWorkflow::autoCloseWaiting($ticket, $days);
                        $this->line("Closed {$ticket->ticket_no}.");
                        $closed++;
                    } catch (Throwable $e) {
                        report($e);   // one bad ticket must not stop the others
                        $this->warn("Skipped {$ticket->ticket_no}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(($dry ? 'Would close ' : 'Closed ').$closed.' ticket(s).');

        return self::SUCCESS;
    }
}
