<?php

namespace App\Services;

/**
 * Capitalisation of the three name columns on an enrolee record.
 *
 * The register is typed by hand from paper records and several people type in
 * whatever their keyboard's Caps Lock was doing: "bello", "BELLO", "Bello ".
 * The same person then appears three ways in the list, three ways in an export
 * and three ways in an audit entry, and searching for "Bello" misses "bello".
 * Names are therefore normalised on the way in, so that one person is one row
 * as far as a reader is concerned.
 *
 * Deliberately only ever applied to surname, first_name and middle_name. The
 * address, phone, and the rest are stored as typed: an address is not a name,
 * and capitalising it would mangle its real shape.
 *
 * Not a mutator on the model on purpose. A mutator would also rewrite the
 * value on every later save, including one that only changes the phone number,
 * which would silently edit the name an administrator had already corrected by
 * hand on a submitted record. Normalising at save time is what the brief asks
 * for, and it leaves untouched rows untouched.
 */
final class NameFormatter
{
    /**
     * Characters that begin a new capital within a single name.
     *
     * A space or a hyphen is a boundary between two words. An apostrophe is
     * not: in O'Neill, O'Laoye and O'gunleye the letter after it starts a name
     * proper, so it is capitalised too. Both the straight quote and the curly
     * one are listed because a phone keyboard produces either.
     */
    private const NAME_BREAKS = ['-', '‐', '‑', '‒', '–', '—'];

    /**
     * Trim, collapse internal whitespace, and title-case.
     *
     * Returns null for input that is blank, so a caller can hand it a raw
     * request value and store whatever comes back without inventing a name for
     * a field the user left empty.
     */
    public static function title(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Collapse every run of whitespace — including tabs and non-breaking
        // spaces, which a pasted name can carry — down to one plain space.
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        if ($collapsed === '') {
            return null;
        }

        return self::capitalise($collapsed);
    }

    /**
     * Capitalise the first letter of each word, leaving apostrophes alone.
     *
     * Not mb_convert_case(): that lowercases what follows an apostrophe, so
     * "O'NEIL" comes back as "O'neil" and "O'Laoye" as "O'laoye". Both spellings
     * are wrong, and both are names that appear on Nigerian registers.
     */
    private static function capitalise(string $value): string
    {
        $out = '';
        $capitaliseNext = true;
        $length = mb_strlen($value, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $character = mb_substr($value, $i, 1, 'UTF-8');

            if (in_array($character, self::NAME_BREAKS, true)) {
                $out .= $character;
                $capitaliseNext = true;

                continue;
            }

            if ($character === "'" || $character === '’') {
                // Written out with the next letter still to be capitalised: an
                // apostrophe here is a prefix inside the name, not the end of
                // it. Leaving the flag false here is what turns O'Neil into
                // O'neil.
                $out .= $character;
                $capitaliseNext = true;

                continue;
            }

            if ($character === ' ') {
                $out .= $character;
                $capitaliseNext = true;

                continue;
            }

            $out .= $capitaliseNext
                ? mb_strtoupper($character, 'UTF-8')
                : mb_strtolower($character, 'UTF-8');

            $capitaliseNext = false;
        }

        return $out;
    }
}