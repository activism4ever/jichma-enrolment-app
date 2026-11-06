<?php

namespace App\Enums;

/**
 * The three roles. There is exactly one global Admin; a Program User and a
 * Reviewer each belong to exactly one program.
 */
enum Role: string
{
    case Admin = 'admin';
    case ProgramUser = 'program_user';
    case Reviewer = 'reviewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::ProgramUser => 'Program User',
            self::Reviewer => 'Reviewer',
        };
    }

    /** Only these roles belong to a program; Admin has program_id = NULL. */
    public function requiresProgram(): bool
    {
        return $this !== self::Admin;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $r) => ['value' => $r->value, 'label' => $r->label()],
            self::cases()
        );
    }
}