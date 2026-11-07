<?php

namespace App\Console\Commands;

use App\Models\Faq;
use Database\Seeders\FaqSeeder;
use Illuminate\Console\Command;

/**
 * Help & FAQ: add the starter questions that are missing.   php artisan faq:seed
 * Safe to run more than once: it never changes an entry that already exists.
 */
class SeedFaq extends Command
{
    protected $signature = 'faq:seed';

    protected $description = 'Add the starter Help & FAQ entries that are missing';

    public function handle(): int
    {
        $before = Faq::count();

        (new FaqSeeder)->run();

        $added = Faq::count() - $before;
        $this->info($added === 0 ? 'Nothing to add: the starter entries are already there.' : "Added {$added} starter entr".($added === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
