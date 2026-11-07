<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Help & FAQ starter content (database/data/faq_starter.php).   php artisan faq:seed
 *
 * Safe to run again and again. For every row of the file, matched by its QUESTION:
 *
 *  - a STARTER row with that question exists  -> its topic, audience, public flag and answer are brought up to date
 *                                                (only when something really changed).
 *  - a CUSTOM row with that question exists   -> left alone (an admin wrote or edited it).
 *  - nothing with that question exists        -> a new starter row is added (visible, at the order from the file).
 *
 * It never deletes anything. It also never changes is_published or sort_order of a row that already exists, so an
 * admin who hid or moved a starter entry keeps that. (To remove a starter entry for good: hide it. A deleted starter
 * entry is added again by the next faq:seed.)
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $this->sync();
    }

    /**
     * @return array{added: int, updated: int, unchanged: int, kept: int}
     *         kept = rows of the file whose question is already used by a custom entry
     */
    public function sync(): array
    {
        /** @var list<array{0: string, 1: list<string>, 2: bool, 3: string, 4: string, 5: int}> $rows */
        $rows = require database_path('data/faq_starter.php');

        $result = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'kept' => 0];

        foreach ($rows as [$topic, $audience, $public, $question, $answer, $order]) {
            $content = [
                'topic' => $topic,
                'audience' => $audience,
                'is_public' => $public,
                'answer' => $answer,
            ];

            $starter = Faq::query()->starter()->where('question', $question)->first();

            if ($starter !== null) {
                $starter->forceFill($content);

                if ($starter->isDirty()) {
                    $starter->saveQuietly();   // quietly: this is the seeder, not an admin edit
                    $result['updated']++;
                } else {
                    $result['unchanged']++;
                }

                continue;
            }

            if (Faq::query()->where('question', $question)->exists()) {
                $result['kept']++;

                continue;
            }

            Faq::create($content + [
                'question' => $question,
                'sort_order' => $order,
                'is_published' => true,
                'source' => Faq::SOURCE_STARTER,
            ]);
            $result['added']++;
        }

        return $result;
    }
}