<?php

namespace App\Console\Commands;

use App\Services\PmNotifier;
use Illuminate\Console\Command;

/**
 * PM integration, Phase C: the daily PM alerts. Scheduled in routes/console.php.
 *
 *   php artisan pm:notify --dry-run            only counts what would be sent
 *   php artisan pm:notify --date=2026-12-01    pretend today is that date (testing)
 */
class NotifyPm extends Command
{
    protected $signature = 'pm:notify
        {--dry-run : Count the alerts without sending them or marking anything}
        {--date= : Pretend today is this date (YYYY-MM-DD), for testing}';

    protected $description = 'Send the preventive maintenance alerts: new tickets, reminders, overdue marking and escalation to the supervisor';

    public function handle(): int
    {
        $today = $this->option('date') ?: null;

        if ($today !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $today)) {
            $this->error('The date must look like 2026-12-01.');

            return self::INVALID;
        }

        $dry = (bool) $this->option('dry-run');
        $r = PmNotifier::runAll($today, $dry);

        $this->info(($dry ? 'Would send: ' : 'Sent: ')."new {$r['new']}, reminders {$r['reminders']}, overdue {$r['overdue']}, escalated {$r['escalated']}.");

        return self::SUCCESS;
    }
}