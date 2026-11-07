<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Marks the searched text inside a question. Safe by construction: the question is split around the match FIRST,
 * then every piece is escaped on its own, and only the <mark> tags are added by us.
 */
class FaqHighlight
{
    public static function question(string $text, string $q): HtmlString
    {
        if ($q === '') {
            return new HtmlString(e($text));
        }

        $parts = @preg_split('/('.preg_quote($q, '/').')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return new HtmlString(e($text));   // e.g. the text is not valid UTF-8: just show it, unmarked
        }

        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1 ? '<mark>'.e($part).'</mark>' : e($part);   // odd pieces are the matches
        }

        return new HtmlString($out);
    }
}