<?php

namespace App\Services;

use ZipArchive;

/**
 * A simplified TEXT preview of a .docx for the file viewer pop-up: paragraphs and tables as plain text.
 * Formatting, images and tables' layout are not shown (the person can download the original).
 * Safe by design: the text is only ever shown with textContent, and a zip bomb is refused by a size limit.
 */
final class DocxPreview
{
    /** document.xml larger than this (uncompressed) is not previewed. */
    public const MAX_XML_BYTES = 8 * 1024 * 1024;

    public const MAX_CHARS = 20000;

    public static function text(string $path): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $stat = $zip->statName('word/document.xml');
            if ($stat === false || (int) $stat['size'] > self::MAX_XML_BYTES) {
                return null;
            }
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (! is_string($xml) || $xml === '') {
            return null;
        }

        $xml = preg_replace(['#</w:p>#', '#<w:br\b[^>]*/?>#', '#<w:cr\b[^>]*/?>#', '#<w:tab\b[^>]*/?>#', '#</w:tc>#'], ["\n", "\n", "\n", "\t", "\t"], $xml) ?? $xml;
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = mb_scrub($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text) ?? '';
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? '';
        $text = trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? '');

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::MAX_CHARS))."\n\n… (preview shortened, download the file to read the rest)";
        }

        return $text;
    }
}
