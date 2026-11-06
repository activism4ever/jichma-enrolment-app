<?php

namespace App\Enums;

enum Education: string
{
    case None = 'none';
    case Primary = 'primary';
    case Secondary = 'secondary';
    case NceOnd = 'nce_ond';
    case HndBsc = 'hnd_bsc';
    case Postgraduate = 'postgraduate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No formal education',
            self::Primary => 'Primary',
            self::Secondary => 'Secondary / SSCE',
            self::NceOnd => 'NCE / OND',
            self::HndBsc => 'HND / BSc',
            self::Postgraduate => 'Postgraduate',
            self::Other => 'Other',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }
}