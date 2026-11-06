<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | This application hashes passwords with argon2id. It is a memory-hard
    | algorithm, which makes cracking leaked password hashes far more
    | expensive than bcrypt. PHP must be built with libargon2 support
    | (it is enabled by default in most cPanel PHP builds; verify with
    | `php -r "print_r(password_algos());"`).
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Only used when HASH_DRIVER=bcrypt.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | The defaults below are the PHP/argon2 library defaults. 64 MiB of
    | memory per hash is deliberate: it is what makes the hash expensive to
    | attack. On a shared host with other tenants, lower ARGON2_MEMORY
    | to 32768 if you hit memory_limit errors; do not lower it below that.
    |
    */

    'argon' => [
        'memory' => (int) env('ARGON2_MEMORY', 65536),
        'threads' => (int) env('ARGON2_THREADS', 2),
        'time' => (int) env('ARGON2_TIME', 4),
        'verify' => true,
    ],

];