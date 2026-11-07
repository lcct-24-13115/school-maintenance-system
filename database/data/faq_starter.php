<?php

/*
| Starter questions of the Help & FAQ page (run: php artisan faq:seed).
|
| The questions live in small part files in database/data/faq_starter/ (part1.php, part2.php, ...), one part per
| phase. This file only joins them, in file-name order, so adding a part never means editing another file.
|
| Row = [topic, audience, public?, question, answer, sort_order]
|   audience  ['all'] or role slugs.   public?  true = may be read before signing in (only with ['all']).
| An answer may use: blank line = new paragraph, "- " bullets, "1. " steps, **bold**, [label](/path).
|
| faq:seed adds the rows that are missing and updates starter rows nobody edited. It never touches an entry an
| admin wrote or edited, and it never deletes anything.
*/

$files = glob(__DIR__.'/faq_starter/part*.php') ?: [];
sort($files);

$rows = [];
foreach ($files as $file) {
    foreach (require $file as $row) {
        $rows[] = $row;
    }
}

return $rows;