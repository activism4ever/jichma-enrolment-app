<?php

use App\Enums\EnroleeStatus;
use App\Models\Enrolee;
use App\Services\NameFormatter;

/*
|--------------------------------------------------------------------------
| Capitalising names on the way in
|--------------------------------------------------------------------------
|
| The register is typed from paper records by several hands, so the same
| surname arrives as "bello", "BELLO" and " Bello ". These tests pin the rule at
| both ends: what is stored when a record is saved, and what the backfill does
| about the rows that are already there.
|
| The rule applies to surname, first_name and middle_name only. Address, phone
| and the rest are stored as typed, and a test says so, because "capitalise the
| name fields" is the kind of rule that otherwise grows into "ucfirst
| everything" and then starts altering addresses.
|
*/

/*
|--------------------------------------------------------------------------
| The formatter itself
|--------------------------------------------------------------------------
*/

test('names are trimmed and title-cased, however they were typed', function (?string $input, ?string $expected) {
    expect(NameFormatter::title($input))->toBe($expected);
})->with([
    'lower case' => ['bello', 'Bello'],
    'upper case' => ['BELLO', 'Bello'],
    'mixed case' => ['BeLLo', 'Bello'],
    'padded' => ['   bello   ', 'Bello'],
    'inner runs of spaces' => ["bello\t\t  abiola", 'Bello Abiola'],
    'non-breaking space' => ["bello\u{00A0}abiola", 'Bello Abiola'],
    'several words' => ['maryam abiola', 'Maryam Abiola'],
    'hyphenated' => ['abiola-jones', 'Abiola-Jones'],
    // The case for hand-rolling this instead of using mb_convert_case(), which
    // lowercases whatever follows an apostrophe: "O'Neil" comes back "O'neil".
    'straight apostrophe, lower case' => ["o'neil", "O'Neil"],
    'straight apostrophe, upper case' => ["O'NEIL", "O'Neil"],
    'straight apostrophe, mixed case' => ["O'Laoye", "O'Laoye"],
    'curly apostrophe' => ["o\u{2019}laoye", "O\u{2019}Laoye"],
    'already correct, untouched' => ['Bello', 'Bello'],
    // "Van Der Berg" is not the form its owner would write, but title-casing
    // particles is a judgement call and the brief asked for title case. Pinned
    // so the choice is visible rather than accidental.
    'particles are capitalised too' => ['van der berg', 'Van Der Berg'],
    'blank becomes null' => ['   ', null],
    'empty becomes null' => ['', null],
    'null stays null' => [null, null],
]);

/*
|--------------------------------------------------------------------------
| What a save stores
|--------------------------------------------------------------------------
*/

test('a draft save capitalises the three name fields', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), [
        'surname' => '  ogonna ',
        'first_name' => 'CHIDI   nkemdi',
        'middle_name' => "o'neil",
    ])->assertRedirect();

    $enrolee = Enrolee::sole();

    expect($enrolee->surname)->toBe('Ogonna')
        ->and($enrolee->first_name)->toBe('Chidi Nkemdi')
        ->and($enrolee->middle_name)->toBe("O'Neil")
        // The composed name reads as one person, not three spellings.
        ->and($enrolee->full_name)->toBe("Ogonna Chidi Nkemdi O'Neil");
});

test('submitting from the form capitalises names too', function () {
    \Tests\Support\PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.submit-new'), $this->validEnroleePayload([
        'surname' => 'ADEYEMI',
        'first_name' => '  amina  ',
        'middle_name' => 'van der berg',
        'photo' => \Tests\Support\PhotoFixtures::jpeg(200, 200),
    ]))->assertRedirect();

    $enrolee = Enrolee::sole();

    expect($enrolee->status)->toBe(EnroleeStatus::Submitted)
        ->and($enrolee->surname)->toBe('Adeyemi')
        ->and($enrolee->first_name)->toBe('Amina')
        ->and($enrolee->middle_name)->toBe('Van Der Berg');
});

test('editing an existing record capitalises the name being changed', function () {
    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->forUser($user)->create(['surname' => 'Bello']);

    $this->put(route('enrolees.update', $draft), [
        'surname' => '  okonkwo  ',
        'first_name' => 'ngozi',
    ])->assertRedirect();

    expect($draft->refresh()->surname)->toBe('Okonkwo')
        ->and($draft->first_name)->toBe('Ngozi');
});

test('a field left out of the request keeps the name already stored', function () {
    // Not a mutator, deliberately: saving the phone number must not silently
    // rewrite the surname, including on a submitted record an administrator has
    // corrected by hand.
    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->forUser($user)->create(['surname' => "O'Laoye"]);

    $this->put(route('enrolees.update', $draft), ['phone' => '08031234567'])
        ->assertRedirect();

    expect($draft->refresh()->surname)->toBe("O'Laoye");
});

test('emptying a name stores null rather than an empty string', function () {
    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->forUser($user)->create(['middle_name' => 'Yusuf']);

    $this->put(route('enrolees.update', $draft), ['middle_name' => '   '])
        ->assertRedirect();

    expect($draft->refresh()->middle_name)->toBeNull();
});

test('the address and the phone are left exactly as typed', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), [
        'surname' => 'bello',
        'address' => 'flat 3b, 12 zaria road, auyo',
        'phone' => '+234 803 123 4567',
    ])->assertRedirect();

    $enrolee = Enrolee::sole();

    // The name is normalised; the address keeps its own shape, because it is not
    // a name and capitalising it would rewrite what the resident typed.
    expect($enrolee->surname)->toBe('Bello')
        ->and($enrolee->address)->toBe('flat 3b, 12 zaria road, auyo')
        // The phone has its own normaliser, which is why it is 080... and not
        // the number that was typed.
        ->and($enrolee->phone)->toBe('08031234567');
});

test('a name is still validated before it is capitalised', function () {
    actingAsProgramUser();

    // Capitalising cannot rescue an over-long name: the length rule runs against
    // the input, and the stored value could only be shorter than that anyway.
    $this->post(route('enrolees.store'), ['surname' => str_repeat('a', 101)])
        ->assertSessionHasErrors('surname');

    expect(Enrolee::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The backfill
|--------------------------------------------------------------------------
|
| The migration file itself is loaded and its up() called, rather than
| `$this->artisan('migrate')`. RefreshDatabase has already run every migration
| before the test body starts, so by the time the rows below exist the migration
| is recorded as applied and Artisan would do nothing at all — a test that
| passes without exercising anything. Requiring the file tests the code that
| will actually run on the host.
|
*/

/** Run the shipped migration, rather than a copy of it in this file. */
function runNameCapitalisationBackfill(): void
{
    $migration = require database_path(
        'migrations/2026_10_01_000004_capitalise_enrolee_names.php'
    );

    $migration->up();
}

test('the backfill capitalises rows that are already in the table', function () {
    // Written straight through the query builder: these rows stand for data
    // typed before the rule existed, so they must not be normalised by the model
    // on the way in — that is the whole point of the migration.
    //
    // Stored and expected side by side in one list, so a fixture and its
    // expectation cannot drift apart. The two that must not change are the point
    // of half of this test.
    $cases = [
        // [ as stored, as it should end up ]
        [['bello', 'amina', 'maryam'], ['Bello', 'Amina', 'Maryam']],
        [['  OGONNA   chidi ', "O'NEIL", null], ['Ogonna Chidi', "O'Neil", null]],
        [['ADEYEMI', 'van   der berg', "O'Laoye"], ['Adeyemi', 'Van Der Berg', "O'Laoye"]],
        [['Bello', 'Amina', 'Yusuf'], ['Bello', 'Amina', 'Yusuf']],
        // A blank middle name is left exactly as found: it is not a spelling to
        // fix, and rewriting it to NULL would be a change of data rather than of
        // capitalisation. Which is why this row is left in the table with the
        // exact whitespace it went in with.
        [['Okonkwo', 'Ngozi', '   '], ['Okonkwo', 'Ngozi', '   ']],
    ];

    $user = actingAsProgramUser();

    foreach ($cases as $index => [[$surname, $first, $middle]]) {
        DB::table('enrolees')->insert([
            'program_id' => $user->program_id,
            'created_by' => $user->id,
            'status' => 'draft',
            'surname' => $surname,
            'first_name' => $first,
            'middle_name' => $middle,
            'nin' => str_pad((string) (7000 + $index), 11, '0', STR_PAD_LEFT),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    runNameCapitalisationBackfill();

    $rows = Enrolee::orderBy('id')->get()
        ->map(fn ($e) => [$e->surname, $e->first_name, $e->middle_name])
        ->all();

    expect($rows)->toBe(array_column($cases, 1));
});

test('the backfill leaves everything else on the row alone', function () {
    $user = actingAsProgramUser();

    DB::table('enrolees')->insert([
        'program_id' => $user->program_id,
        'created_by' => $user->id,
        'status' => 'submitted',
        'surname' => 'bello',
        'first_name' => 'amina',
        'middle_name' => null,
        'address' => 'flat 3b, 12 zaria road, auyo',
        'nin' => '12345678901',
        'submitted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $before = Enrolee::sole();

    runNameCapitalisationBackfill();

    $after = $before->fresh();

    // A submitted registration is attested. Cleaning up the spelling of a name
    // is allowed; changing anything else about the record is not, and the audit
    // trail must not record an administrator editing it.
    expect($after->surname)->toBe('Bello')
        ->and($after->address)->toBe($before->address)
        ->and($after->nin)->toBe($before->nin)
        ->and($after->status)->toBe(EnroleeStatus::Submitted)
        ->and($after->submitted_at->equalTo($before->submitted_at))->toBeTrue()
        ->and(\App\Models\AuditLog::count())->toBe(0);
});