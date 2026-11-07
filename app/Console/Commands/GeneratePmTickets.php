<?php

namespace App\Console\Commands;

use App\Services\PmTicketGenerator;
use Illuminate\Console\Command;

/**
 * PM Scheduler, Phase 2: make the preventive-maintenance tickets that are due. Scheduled daily in routes/console.php.
 *
 *   php artisan pm:generate --dry-run            list what would be made
 *   php artisan pm:generate --date=2026-12-01    pretend today is that date (testing)
 */
class GeneratePmTickets extends Command
{
    protected $signature = 'pm:generate
        {--dry-run : List the tickets that would be made without making them}
        {--date= : Pretend today is this date (YYYY-MM-DD), for testing}
        {--trigger=command : Written to the run log (schedule or command)}';

    protected $description = 'Create the preventive maintenance tickets that are due';

    public function handle(): int
    {
        $today = $this->option('date') ?: null;

        if ($today !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $today)) {
            $this->error('The date must look like 2026-12-01.');

            return self::INVALID;
        }

        if ($this->option('dry-run')) {
            $due = PmTicketGenerator::dueSchedules($today);

            foreach ($due as $s) {
                $this->line("Would make a ticket for \"{$s->title}\" (due {$s->next_due_date->toDateString()}).");
            }

            $this->info('Would make '.$due->count().' ticket(s).');

            return self::SUCCESS;
        }

        $trigger = in_array($this->option('trigger'), ['schedule', 'command'], true) ? $this->option('trigger') : 'command';
        $run = PmTicketGenerator::runDue($trigger, $today);

        $this->info("Checked {$run->schedules_checked}, made {$run->tickets_created} ticket(s), skipped {$run->skipped}, errors {$run->error_count}.");

        foreach ((array) $run->errors as $error) {
            $this->warn($error);
        }

        return $run->error_count > 0 ? self::FAILURE : self::SUCCESS;
    }
}