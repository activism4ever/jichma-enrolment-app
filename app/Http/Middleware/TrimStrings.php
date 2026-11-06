<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

/**
 * Trims incoming strings, except the NIN.
 *
 * Trimming is the right default: it means a stray space at either end of a name
 * or address never reaches the database, and never has to be explained to a
 * user filling in a form on a phone.
 *
 * The NIN is the exception because the brief pins its shape to exactly
 * `^\d{11}$` and asks for anything else to be refused. Trimming first would let
 * "12345678901 " through on the server even though the rule says it should not,
 * which would make the rule depend on which middleware ran before it. Excluding
 * the field here keeps the validation rule honest: what the regex describes is
 * what the server actually checks.
 *
 * The form is not left to the user to cope with that. app.js strips every
 * non-digit character from the NIN as it is typed, so a value pasted in with a
 * stray space is corrected in the field before it is ever submitted.
 */
class TrimStrings extends Middleware
{
    /**
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
        'nin',
    ];
}