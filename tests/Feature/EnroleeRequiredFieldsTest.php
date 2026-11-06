<?php

use App\Enums\EnroleeStatus;
use App\Models\Enrolee;
use Tests\Support\PhotoFixtures;

/*
|--------------------------------------------------------------------------
| Required fields, NIN shape and the draft/submit split
|--------------------------------------------------------------------------
|
| The brief sets one rule for the browser and another for the server:
|
|   Draft   - whatever has been typed must save, provided what is there is
|             well formed. A partial form is a legitimate work in progress.
|   Submit  - every field is required except the middle name, and the
|             photograph has to be there.
|
| The tests below pin the server half of that. The `required` attributes and
| the NIN pattern on the inputs are a convenience that catches the obvious
| mistakes before a round trip; they are deliberately not what makes these
| rules true, because a crafted request bypasses them entirely. Every case
| here posts raw data straight at the route.
|
*/

/** Every field the brief requires in order to submit. */
function requiredSubmitFields(): array
{
    return [
        'surname', 'first_name', 'gender', 'date_of_birth', 'marital_status',
        'education', 'phone', 'nin', 'address', 'lga_id', 'ward_id',
        'facility_id',
    ];
}

/*
|--------------------------------------------------------------------------
| Drafts still save
|--------------------------------------------------------------------------
*/

test('a draft saves with only some of the required fields filled in', function () {
    actingAsProgramUser();

    // Surname, phone and NIN are filled in. Gender, date of birth, marital
    // status, education, address and the whole location chain are not.
    $this->post(route('enrolees.store'), [
        'surname' => 'Bello',
        'phone' => '08031234567',
        'nin' => '12345678901',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $enrolee = Enrolee::sole();

    expect($enrolee->surname)->toBe('Bello')
        ->and($enrolee->status)->toBe(EnroleeStatus::Draft)
        ->and($enrolee->gender)->toBeNull()
        ->and($enrolee->date_of_birth)->toBeNull()
        ->and($enrolee->address)->toBeNull()
        ->and($enrolee->lga_id)->toBeNull()
        ->and($enrolee->nin)->toBe('12345678901')
        // The phone arrives in one of several formats and is stored canonically.
        ->and($enrolee->phone)->toBe('08031234567');
});

test('a draft saves with every dropdown left on its placeholder', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), [
        'surname' => 'Bello',
        'gender' => '',
        'marital_status' => '',
        'education' => '',
        'lga_id' => '',
        'ward_id' => '',
        'facility_id' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Enrolee::sole()->status)->toBe(EnroleeStatus::Draft);
});

test('a draft saves without the middle name', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Bello', 'first_name' => 'Amina'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Enrolee::sole()->middle_name)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Submitting requires everything
|--------------------------------------------------------------------------
*/

test('a submission is refused when each required field is left blank in turn', function (string $field) {
    $user = actingAsProgramUser();

    $payload = $this->validEnroleePayload();
    // Blank rather than absent: this is what a form actually sends when the
    // user touches a field and leaves it empty. An absent key would be filled
    // in from the stored draft by SubmitEnroleeRequest, which is a different
    // code path and is covered on its own below.
    $payload[$field] = '';
    $payload['photo'] = PhotoFixtures::jpeg(200, 200);

    // A fresh draft per case, so one failure cannot leak into the next.
    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), $payload)
        ->assertSessionHasErrors($field);

    // The record must still be a draft, not a half-written submission.
    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft);
})->with([
    'surname' => 'surname',
    'first name' => 'first_name',
    'gender' => 'gender',
    'date of birth' => 'date_of_birth',
    'marital status' => 'marital_status',
    'education' => 'education',
    'phone' => 'phone',
    'NIN' => 'nin',
    'address' => 'address',
    'LGA' => 'lga_id',
    'ward' => 'ward_id',
    'facility' => 'facility_id',
]);

test('a submission with every field present but no photograph is refused', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), $this->validEnroleePayload())
        ->assertSessionHasErrors('photo');

    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft);
});

test('the middle name is the one field a submission may leave out', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $payload = $this->validEnroleePayload();
    unset($payload['middle_name']);
    $payload['photo'] = PhotoFixtures::jpeg(200, 200);

    $this->post(route('enrolees.submit', $draft), $payload)
        ->assertSessionHasNoErrors();

    expect($draft->refresh())
        ->status->toBe(EnroleeStatus::Submitted)
        ->middle_name->toBeNull();
});

test('a fully valid submission is still accepted', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), $this->validEnroleePayload([
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertSessionHasNoErrors();

    expect($draft->refresh()->status)->toBe(EnroleeStatus::Submitted);
});

/*
|--------------------------------------------------------------------------
| Dropdowns: the placeholder is not an answer
|--------------------------------------------------------------------------
*/

test('a submission that leaves a dropdown on its placeholder is refused', function (string $field) {
    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), $this->validEnroleePayload([
        $field => '',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertSessionHasErrors($field);

    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft);
})->with([
    'gender' => 'gender',
    'marital status' => 'marital_status',
    'education' => 'education',
    'LGA' => 'lga_id',
    'ward' => 'ward_id',
    'facility' => 'facility_id',
]);

test('gender offers only male and female, and nothing else is accepted', function () {
    $user = actingAsProgramUser();

    $this->get(route('enrolees.create'))
        ->assertOk()
        ->assertSee('value="male"', false)
        ->assertSee('value="female"', false)
        // "Not stated" was the old blank option; a gender must now be chosen.
        ->assertDontSee('Not stated');

    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), $this->validEnroleePayload([
        'gender' => 'not_stated',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertSessionHasErrors('gender');

    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft);
});

/*
|--------------------------------------------------------------------------
| NIN: exactly eleven digits, on both paths
|--------------------------------------------------------------------------
*/

test('a NIN that is not exactly eleven digits is refused on draft save', function (string $nin) {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Bello', 'nin' => $nin])
        ->assertSessionHasErrors('nin');

    // Nothing is written at all: a bad NIN never reaches the table.
    expect(Enrolee::count())->toBe(0);
})->with([
    'ten digits' => '1234567890',
    'twelve digits' => '123456789012',
    'nine digits' => '123456789',
    'one digit' => '1',
    'letters' => 'ABCDEFGHIJK',
    'letters among digits' => '12345ABCDE1',
    'dashes' => '12345-6789-1',
    'internal spaces' => '123 456 789 1',
    'surrounding spaces' => ' 12345678901 ',
    'a decimal' => '1234567890.1',
    'a plus sign' => '+1234567890',
    'eleven digits and a letter' => '12345678901x',
]);

test('a NIN that is not exactly eleven digits is refused on submit', function (string $nin) {
    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), $this->validEnroleePayload([
        'nin' => $nin,
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertSessionHasErrors('nin');

    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft);
})->with([
    'ten digits' => '1234567890',
    'twelve digits' => '123456789012',
    'letters' => 'ABCDEFGHIJK',
    'dashes' => '12345-6789-1',
    'surrounding spaces' => ' 12345678901 ',
    'a decimal' => '1234567890.1',
]);

test('the NIN rejection message is the one the brief specifies', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Bello', 'nin' => '1234567890'])
        ->assertSessionHasErrors([
            'nin' => 'NIN must be exactly 11 digits.',
        ]);
});

test('exactly eleven digits is accepted on draft save', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Bello', 'nin' => '12345678901'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Enrolee::sole()->nin)->toBe('12345678901');
});

test('a draft already holding every field can submit with only a photograph', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    // Save the whole form as a draft first, with no photograph.
    $this->post(route('enrolees.store'), $this->validEnroleePayload())
        ->assertRedirect()->assertSessionHasNoErrors();

    $draft = Enrolee::sole();

    expect($draft->status)->toBe(EnroleeStatus::Draft)
        ->and($draft->photo_path)->toBeNull();

    // The record page posts only the photograph; the stored values are used.
    $this->post(route('enrolees.submit', $draft), [
        'photo' => PhotoFixtures::jpeg(200, 200),
    ])->assertSessionHasNoErrors();

    expect($draft->refresh())
        ->status->toBe(EnroleeStatus::Submitted)
        ->photo_path->not->toBeNull();
});

test('a NIN still cannot be reused by a user in a different program', function () {
    $first = actingAsProgramUser($this->program('CEMOG'));

    $this->post(route('enrolees.store'), [
        'surname' => 'Bello',
        'nin' => '12345678901',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('logout'));

    // A second user, in a different program entirely.
    $second = actingAsProgramUser($this->program('PLHIV'));

    expect($second->program_id)->not->toBe($first->program_id);

    $this->post(route('enrolees.store'), [
        'surname' => 'Copy',
        'nin' => '12345678901',
    ])->assertSessionHasErrors('nin');

    expect(Enrolee::count())->toBe(1);
});

test('a duplicate NIN is refused on submit as well as on draft save', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Taken', 'nin' => '12345678901'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), $this->validEnroleePayload([
        'nin' => '12345678901',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertSessionHasErrors('nin');

    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft);
});

/*
|--------------------------------------------------------------------------
| Phone
|--------------------------------------------------------------------------
*/

test('a phone number that is not a Nigerian mobile is refused', function (string $phone) {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Bello', 'phone' => $phone])
        ->assertSessionHasErrors('phone');

    expect(Enrolee::count())->toBe(0);
})->with([
    'too short' => '0801234',
    'too long' => '080123456789012',
    'letters' => '080CALLME',
    'a fixed-line prefix' => '02012345678',
    'letters among digits' => '0801234567a',
    'words' => 'call me',
]);

test('a phone number is required in order to submit', function () {
    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), $this->validEnroleePayload([
        'phone' => '',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertSessionHasErrors('phone');

    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft);
});

test('a phone number in an international format is stored canonically', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Bello', 'phone' => '+234 803 123 4567'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Enrolee::sole()->phone)->toBe('08031234567');
});

/*
|--------------------------------------------------------------------------
| The form advertises its own rules
|--------------------------------------------------------------------------
*/

test('the form marks every required field and labels the middle name optional', function () {
    actingAsProgramUser();

    $html = $this->get(route('enrolees.create'))->assertOk()->getContent();

    foreach (['surname', 'first_name', 'gender', 'date_of_birth', 'marital_status',
        'education', 'phone', 'nin', 'address', 'lga_id', 'ward_id', 'facility_id'] as $field) {
        // The browser's own required attribute is what makes each asterisk
        // true rather than decorative.
        expect($html)->toMatch('/id="'.preg_quote($field, '/').'"[^>]*\brequired\b/');
    }

    expect($html)->toContain('Middle name (optional)');

    // The one input that must NOT be required.
    expect($html)->not->toMatch('/<input id="middle_name"[^>]*\brequired\b/');
});

test('the save-draft button opts out of browser validation', function () {
    actingAsProgramUser();

    $html = $this->get(route('enrolees.create'))->assertOk()->getContent();

    // Without this the browser refuses to send a half-filled form at all, so
    // the server's draft rules would never get the chance to run.
    expect($html)->toMatch('/<button[^>]*\bformnovalidate\b[^>]*>/');
});

test('the NIN input carries the pattern, inputmode and length the brief asks for', function () {
    actingAsProgramUser();

    $html = $this->get(route('enrolees.create'))->assertOk()->getContent();

    expect($html)->toMatch('/id="nin"[^>]*\binputmode="numeric"/')
        ->and($html)->toMatch('/id="nin"[^>]*\bmaxlength="11"/')
        ->and($html)->toMatch('/id="nin"[^>]*\bpattern="\\\\d\{11\}"/')
        // The live x/11 readout the counter updates.
        ->and($html)->toContain('data-nin-counter');
});

test('the form offers both a camera and a file button for the photograph', function () {
    actingAsProgramUser();

    $html = $this->get(route('enrolees.create'))->assertOk()->getContent();

    expect($html)->toContain('Take photo')
        ->and($html)->toContain('Choose from files')
        // capture="user" asks for the front camera, which a passport photo needs.
        ->and($html)->toMatch('/accept="image\/\*"\s+capture="user"/')
        // The picker offers only the two formats the server accepts.
        ->and($html)->toMatch('/accept="image\/jpeg,image\/png"\s+class="visually-hidden"/')
        // Exactly one input in the form's name space carries the photograph.
        ->and($html)->toMatch('/name="photo"/');
});

test('every dropdown opens on a placeholder that is not a real answer', function () {
    actingAsProgramUser();

    $html = $this->get(route('enrolees.create'))->assertOk()->getContent();

    // Each select starts with an empty-valued option carrying its prompt text.
    // An empty value is what makes `required` refuse it server-side.
    foreach (['gender', 'marital_status', 'education', 'lga_id'] as $field) {
        expect($html)->toMatch(
            '/<select id="'.preg_quote($field, '/').'"[^>]*>\s*<option value="">[^<]+<\/option>/s'
        );
    }
});