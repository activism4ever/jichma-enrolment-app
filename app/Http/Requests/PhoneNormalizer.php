<?php

namespace App\Http\Requests;

/**
 * Nigerian mobile number normalisation.
 *
 * Stored in the database as a local 11-digit number beginning 0 (08012345678),
 * which is the format the program's existing paper records use. Numbers
 * submitted in international form are converted to that shape so that
 * searching by phone matches regardless of how the user typed it.
 */
final class PhoneNormalizer
{
    /**
     * Normalise to 08012345678, or return null if the value is not a plausible
     * Nigerian mobile number.
     *
     * Accepts: 08012345678, +2348012345678, 2348012345678, 0801 234 5678,
     * 8012345678.
     */
    public static function normalize(?string $input): ?string
    {
        if (blank($input)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            return null;
        }

        // Strip the international prefix.
        if (str_starts_with($digits, '234')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '00234')) {
            $digits = substr($digits, 5);
        }

        // Local numbers starting 0 are already in the target shape.
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Nigerian mobile numbers are 10 digits after the leading 0, and the
        // network codes in use start 70, 80, 81, 90 or 91.
        if (! preg_match('/^(70|80|81|90|91)\d{8}$/', $digits)) {
            return null;
        }

        return '0'.$digits;
    }

    /** True when the value normalises to something valid. */
    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }
}