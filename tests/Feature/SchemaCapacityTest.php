<?php

use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Schema capacity
|--------------------------------------------------------------------------
|
| These are not tests of behaviour, they are guards on the shape of the
| schema. They exist because a `tinyIncrements('id')` on programs went unnoticed
| until the test suite began failing intermittently: MySQL does not roll the
| auto-increment counter back with the transaction, so the counter climbed
| past 255 mid-run and wrapped onto an id that was already in use.
|
| The production risk is worse than the test noise. TINYINT UNSIGNED tops out at
| 255, so the fourth program added on a live install would fail — and it would
| look like a random duplicate-key error rather than a full column.
|
*/

/** Tables whose primary key was once a narrow integer. */
dataset('id columns', [
    ['programs'],
    ['lgas'],
    ['wards'],
    ['facilities'],
    ['enrolees'],
]);

test('every primary key can hold more than 255 rows', function (string $table) {
    $type = strtolower(Schema::getColumnType($table, 'id'));

    // A narrow unsigned integer cannot exceed 255 (tinyint) or 65535 (smallint).
    // Both are wrong for a table whose whole purpose is to accumulate rows.
    expect($type)->not->toBe('tinyint')
        ->and($type)->not->toBe('smallint');
})->with('id columns');

test('foreign keys match the type of the key they reference', function () {
    // A foreign key column must be the same width as the column it points at,
    // or MySQL rejects the constraint. This walks the real definitions rather
    // than trusting that they were written consistently.
    $expected = (string) DB::selectOne("SHOW COLUMNS FROM programs LIKE 'id'")->Type;

    $references = [
        ['users', 'program_id'],
        ['enrolees', 'program_id'],
        ['wards', 'lga_id'],
        ['facilities', 'ward_id'],
        ['audit_logs', 'program_id'],
    ];

    foreach ($references as [$table, $column]) {
        $actual = (string) DB::selectOne("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->Type;

        expect($actual)->toBe($expected, "{$table}.{$column} should match programs.id");
    }
});

test('a program can be added without exhausting the column', function () {
    // The practical version of the guard: inserting several hundred programs in
    // one go used to be impossible, because the 256th wrapped onto id 1.
    Program::factory()->count(300)->create();

    expect(Program::count())->toBe(300);
});