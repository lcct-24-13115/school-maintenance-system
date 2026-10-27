<?php

namespace App\Support;

/**
 * Safe CSV writing for Excel (Merge Phase M4).
 *
 * A text cell that STARTS with = + - @ (or a tab / carriage return) is run as a formula by Excel and
 * LibreOffice ("CSV injection"). Ticket text is typed by users, so every text cell gets a leading apostrophe,
 * which the spreadsheet shows as plain text. Numbers are left alone.
 */
final class Csv
{
    /** The UTF-8 byte order mark that makes Excel read accents and Filipino names correctly. */
    public const BOM = "\xEF\xBB\xBF";

    public static function cell(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        return $text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    /** @param  resource  $handle */
    public static function write($handle, array $row): void
    {
        // the 5th argument (escape) is passed on purpose: relying on its default is deprecated in PHP 8.4
        fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '');
    }
}
