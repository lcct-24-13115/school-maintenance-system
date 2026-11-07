<?php

namespace App\Console\Commands;

use Database\Seeders\FaqSeeder;
use Illuminate\Console\Command;

/**
 * Help & FAQ: bring the starter questions up to date.   php artisan faq:seed
 * Safe to run more than once: it adds what is missing, updates starter entries nobody edited, and never touches
 * an entry an admin wrote or edited in the FAQ manager. It never deletes anything.
 */
class SeedFaq extends Command
{
    protected $signature = 'faq:seed';

    protected $description = 'Add missing Help & FAQ starter entries and update the starter ones nobody has edited';

    public function handle(): int
    {
        $r = (new FaqSeeder)->sync();

        if ($r['added'] === 0 && $r['updated'] === 0) {
            $this->info('Nothing to change: the starter entries are already up to date.');
        } else {
            $this->info("Starter entries: added {$r['added']}, updated {$r['updated']}, unchanged {$r['unchanged']}.");
        }

        if ($r['kept'] > 0) {
            $this->line("Left alone: {$r['kept']} starter question(s) that an admin already wrote or edited.");
        }

        return self::SUCCESS;
    }
}