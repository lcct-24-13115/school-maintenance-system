<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Help & FAQ starter content (database/data/faq_starter.php).
 * Safe to run again: a row is only ADDED when no entry with the same question exists. Nothing already there is changed.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array{0: string, 1: list<string>, 2: bool, 3: string, 4: string, 5: int}> $rows */
        $rows = require database_path('data/faq_starter.php');

        foreach ($rows as [$topic, $audience, $public, $question, $answer, $order]) {
            Faq::firstOrCreate(
                ['question' => $question],
                [
                    'topic' => $topic,
                    'audience' => $audience,
                    'is_public' => $public,
                    'answer' => $answer,
                    'sort_order' => $order,
                    'is_published' => true,
                ]
            );
        }
    }
}
