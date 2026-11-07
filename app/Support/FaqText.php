<?php

namespace App\Support;

/**
 * Turns the text of a FAQ answer into safe HTML. Everything is escaped FIRST, then only these marks are turned back into tags:
 *
 *   a blank line          starts a new paragraph (single line breaks inside a paragraph become <br>)
 *   "- item"              bullet list
 *   "1. step" / "1) step" numbered list
 *   **bold**              <strong>
 *   [label](/some/path)   a link to a page of THIS site (the address must start with a single "/")
 *
 * Nothing else is ever output as HTML, so an answer can never carry a script, an image or an outside link.
 */
class FaqText
{
    public static function toHtml(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];

        $out = [];
        $para = [];
        $list = null;   // 'ul' | 'ol' | null

        $closePara = function () use (&$out, &$para) {
            if ($para !== []) {
                $out[] = '<p>'.implode('<br>', $para).'</p>';
                $para = [];
            }
        };
        $closeList = function () use (&$out, &$list) {
            if ($list !== null) {
                $out[] = '</'.$list.'>';
                $list = null;
            }
        };

        foreach ($lines as $raw) {
            $line = trim($raw);

            if ($line === '') {
                $closePara();
                $closeList();

                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $line, $m)) {
                $closePara();
                if ($list !== 'ul') {
                    $closeList();
                    $out[] = '<ul>';
                    $list = 'ul';
                }
                $out[] = '<li>'.self::inline($m[1]).'</li>';

                continue;
            }

            if (preg_match('/^\d+[.)]\s+(.+)$/', $line, $m)) {
                $closePara();
                if ($list !== 'ol') {
                    $closeList();
                    $out[] = '<ol>';
                    $list = 'ol';
                }
                $out[] = '<li>'.self::inline($m[1]).'</li>';

                continue;
            }

            $closeList();
            $para[] = self::inline($line);
        }

        $closePara();
        $closeList();

        return implode("\n", $out);
    }

    private static function inline(string $text): string
    {
        $s = e($text);

        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;

        // only addresses of this site: "/" followed by a safe character (so "//evil.com" and "javascript:" are never links)
        $s = preg_replace('/\[([^\]]+)\]\((\/(?!\/)[A-Za-z0-9_\-\/\.\?=&;#%]*)\)/', '<a href="$2">$1</a>', $s) ?? $s;

        return $s;
    }
}
