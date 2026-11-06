<?php

namespace App\Enums;

enum EnroleeStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
        };
    }

    /**
     * The backing values, for validation rules and filter whitelists. Kept
     * here so adding a third status cannot leave a hard-coded list behind in
     * one of those places.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}