<?php

namespace App\Services;

/**
 * Neutralises spreadsheet formula injection in exported CSV and XLSX cells.
 *
 * A cell beginning with =, +, - or @ is interpreted as a formula by Excel,
 * LibreOffice and Google Sheets. An enrolee surname of "=HYPERLINK(...)" or an
 * address starting with "+1-555-evil" would therefore execute on the machine of
 * whoever opens the export. Prefixing with a single quote is the standard,
 * widely-supported mitigation: the leading apostrophe is treated as a text
 * marker and is not displayed.
 */
final class SpreadsheetSanitizer
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@'];

    /** Leading control characters can also be used to bypass a naive check. */
    private const CONTROL_CHARS = ["\t", "\r", "\n", "\0"];

    /**
     * Make one value safe for a spreadsheet cell.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function cell($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return 'Yes';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $string = (string) $value;

        // A leading tab/newline makes Excel treat the following character as
        // the real start of the cell, so strip control characters from the front.
        $string = ltrim($string, implode('', self::CONTROL_CHARS));

        if ($string === '') {
            return '';
        }

        if (in_array($string[0], self::DANGEROUS_PREFIXES, true)) {
            return "'".$string;
        }

        return $string;
    }

    /**
     * Sanitise a whole row of mixed values.
     *
     * @param  iterable  $row
     * @return list<string>
     */
    public static function row(iterable $row): array
    {
        $out = [];

        foreach ($row as $value) {
            $out[] = self::cell($value);
        }

        return $out;
    }
}