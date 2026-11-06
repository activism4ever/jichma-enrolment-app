<?php

use App\Enums\EnroleeStatus;
use App\Models\Enrolee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PhotoFixtures;

/*
|--------------------------------------------------------------------------
| Submitting straight from the form
|--------------------------------------------------------------------------
|
| The brief: a user who has finished the form should not have to save a draft
| first. So the create and edit forms each carry two buttons over one set of
| fields — Save draft posts the form's own action and accepts partial data,
| Submit registration overrides it with formaction and is validated in full.
|
| What must hold when that submit button is used:
|
|   - a complete form with a photograph creates a record already submitted;
|   - an incomplete form creates no submitted record, and what was typed is
|     kept as a draft rather than thrown away;
|   - the duplicate-NIN block applies here too, including the race that only
|     the database index can catch.
|
*/

/*
|--------------------------------------------------------------------------
| The buttons are there
|--------------------------------------------------------------------------
*/

test('the create form offers a primary Submit button alongside a secondary Save draft', function () {
    actingAsProgramUser();

    $html = $this->get(route('enrolees.create'))->assertOk()->getContent();

    expect($html)->toMatch('/<button[^>]*\bclass="btn btn-primary"[^>]*>/')
        ->and($html)->toContain('Submit registration')
        ->and($html)->toContain('Save draft')
        // Save draft is the secondary style, so it cannot be mistaken for the
        // action that locks the record.
        ->and($html)->toMatch('/<button[^>]*\bclass="btn btn-secondary"[^>]*\bformnovalidate\b[^>]*>/s')
        // The submit button posts elsewhere, and asks first.
        ->and($html)->toMatch('/formaction="'.preg_quote(route('enrolees.submit-new'), '/').'"/')
        ->and($html)->toContain('Once submitted you cannot edit this record. Submit now?');
});

test('the edit form offers the same two buttons', function () {
    $user = actingAsProgramUser();

    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $html = $this->get(route('enrolees.edit', $draft))->assertOk()->getContent();

    expect($html)->toMatch('/formaction="'.preg_quote(route('enrolees.submit', $draft), '/').'"/')
        ->and($html)->toContain('Submit registration')
        ->and($html)->toContain('Save changes');
});

/*
|--------------------------------------------------------------------------
| Submitting a brand-new record
|--------------------------------------------------------------------------
*/

test('submitting a brand-new form creates the record already submitted', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $response = $this->post(route('enrolees.submit-new'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]));

    // To the record page, with the success message. Keyed as `submitted` rather
    // than the ordinary `status` so that this wording can only ever come from a
    // submission that has just happened.
    $enrolee = Enrolee::sole();

    $response->assertRedirect(route('enrolees.show', $enrolee))
        ->assertSessionHas('submitted', 'Registration submitted. This record is now locked.')
        ->assertSessionMissing('status');

    expect($enrolee->status)->toBe(EnroleeStatus::Submitted)
        ->and($enrolee->submitted_at)->not->toBeNull()
        ->and($enrolee->submitted_by)->not->toBeNull()
        // Saved from the same submission, not by a separate draft save.
        ->and($enrolee->photo_path)->not->toBeNull()
        ->and($enrolee->first_name)->toBe('Amina')
        ->and($enrolee->facility_id)->not->toBeNull();
});

test('a submitted-by-submit record is logged in the audit trail', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.submit-new'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertRedirect();

    // No separate draft was created, so there is no 'enrolee_created' entry
    // either: this was one action, not two.
    expect(\App\Models\AuditLog::where('action', 'enrolee_submitted')->count())->toBe(1)
        ->and(\App\Models\AuditLog::where('action', 'enrolee_created')->count())->toBe(0);
});

test('a submitted record cannot be edited afterwards by its creator', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.submit-new'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertRedirect();

    $enrolee = Enrolee::sole();

    $this->get(route('enrolees.edit', $enrolee))->assertForbidden();

    // Nor by posting to the update route directly.
    $this->post(route('enrolees.update', $enrolee), ['surname' => 'Hacked'])
        ->assertForbidden();

    expect($enrolee->refresh()->surname)->toBe('Bello');
});

test('submitting an incomplete form creates no submitted record', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $payload = $this->validEnroleePayload();
    unset($payload['address']);
    $payload['photo'] = PhotoFixtures::jpeg(200, 200);

    $this->post(route('enrolees.submit-new'), $payload);

    // The record is kept as a draft, so nothing is lost, but it is not submitted.
    $enrolee = Enrolee::sole();

    expect($enrolee->status)->toBe(EnroleeStatus::Draft)
        ->and($enrolee->submitted_at)->toBeNull()
        ->and($enrolee->submitted_by)->toBeNull()
        ->and($enrolee->surname)->toBe('Bello');
});

test('an incomplete submission keeps what was typed and reports what is missing', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.submit-new'), [
        'surname' => 'Bello',
        'first_name' => 'Amina',
        'phone' => '08031234567',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]);

    // Following the redirect is the point: the user must land on the edit page
    // and be told what is outstanding, not be shown a bare form again.
    $this->followingRedirects()
        ->post(route('enrolees.submit-new'), [
            'surname' => 'Bello',
            'first_name' => 'Amina',
            'photo' => PhotoFixtures::jpeg(200, 200),
        ])
        ->assertOk()
        ->assertSee('Choose a gender.')
        ->assertSee('Enter the residential address.');
});

test('the photograph survives a failed submission because the draft is saved', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    // Address is missing, so the submission fails — but the photograph, which a
    // browser cannot re-populate after a redirect, must not be lost with it.
    $this->post(route('enrolees.submit-new'), [
        'surname' => 'Bello',
        'first_name' => 'Amina',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]);

    $enrolee = Enrolee::sole();

    expect($enrolee->photo_path)->not->toBeNull()
        ->and($enrolee->status)->toBe(EnroleeStatus::Draft);

    Storage::disk('private')->assertExists($enrolee->photo_path);
});

test('a failed submission redirects to the edit page with the errors attached', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $response = $this->post(route('enrolees.submit-new'), [
        'surname' => 'Bello',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]);

    $enrolee = Enrolee::sole();

    $response->assertRedirect(route('enrolees.edit', $enrolee))
        ->assertSessionHasErrors(['gender', 'first_name', 'phone', 'nin', 'address']);
});

test('a failed submission on an existing draft keeps the record a draft', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();
    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), [
        'surname' => 'Bello',
        'first_name' => 'Amina',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ])->assertRedirect(route('enrolees.edit', $draft));

    $draft->refresh();

    expect($draft->status)->toBe(EnroleeStatus::Draft)
        ->and($draft->submitted_at)->toBeNull()
        ->and($draft->first_name)->toBe('Amina')
        ->and($draft->photo_path)->not->toBeNull();
});

test('a submission with no photograph is refused and the rest is kept as a draft', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.submit-new'), $this->validEnroleePayload())
        ->assertSessionHasErrors('photo');

    // Every other field was complete, so the draft keeps all of it and only the
    // photograph is outstanding. It is not submitted, because it has no photo.
    $enrolee = Enrolee::sole();

    expect($enrolee->status)->toBe(EnroleeStatus::Draft)
        ->and($enrolee->photo_path)->toBeNull()
        ->and($enrolee->surname)->toBe('Bello')
        ->and($enrolee->facility_id)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The duplicate-NIN block applies on submit too
|--------------------------------------------------------------------------
*/

test('a duplicate NIN blocks a submission from a brand-new form', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'First', 'nin' => '12345678901'])
        ->assertRedirect();

    $this->post(route('enrolees.submit-new'), $this->validEnroleePayload([
        'nin' => '12345678901',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]))->assertSessionHasErrors('nin');

    // The existing record is untouched and the new one was not submitted.
    expect(Enrolee::count())->toBe(1)
        ->and(Enrolee::sole()->status)->toBe(EnroleeStatus::Draft);
});

/**
 * The gap the friendly message has to cover.
 *
 * Validation checks the NIN with a SELECT, and the insert happens afterwards.
 * Two requests can both read "free" and both try to write. Only the database can
 * arbitrate, so the unique index throws a QueryException where a validation
 * rule would have returned a message.
 *
 * Driving that through HTTP is impossible: the row has to appear between the
 * two statements, and the request is synchronous. So this calls the service
 * directly, which is precisely the layer that has to cope — validation already
 * passed by the time the controller reaches it.
 */
test('a duplicate NIN that appears after validation reports the friendly message', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();

    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    // The competing registration lands first, inserted straight through the
    // query builder. Through Eloquent it would have been refused by the same
    // index, which is the whole point: this row represents the one that won the
    // race, and it exists by the time our statement reaches the database.
    DB::table('enrolees')->insert([
        'program_id' => $user->program_id,
        'created_by' => $user->id,
        'status' => 'draft',
        'surname' => 'Winner',
        'nin' => '12345678901',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(\App\Services\EnroleeService::class);

    expect(fn () => $service->submit(
        $draft,
        ['nin' => '12345678901', 'surname' => 'Bello'],
        $user,
        PhotoFixtures::jpeg(200, 200)
    ))->toThrow(\Illuminate\Validation\ValidationException::class, 'This NIN is already registered.');

    // Left as a draft, not half-submitted.
    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft)
        ->and($draft->submitted_at)->toBeNull();
});

test('a duplicate NIN on a brand-new submission is caught by the index too', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();

    DB::table('enrolees')->insert([
        'program_id' => $user->program_id,
        'created_by' => $user->id,
        'status' => 'submitted',
        'surname' => 'Winner',
        'nin' => '12345678901',
        'submitted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(\App\Services\EnroleeService::class);

    expect(fn () => $service->createSubmitted(
        ['nin' => '12345678901', 'surname' => 'Bello'],
        $user,
        PhotoFixtures::jpeg(200, 200)
    ))->toThrow(\Illuminate\Validation\ValidationException::class, 'This NIN is already registered.');

    // The insert was rolled back: still only the row that got there first, and
    // no photograph left orphaned on disk for a record that does not exist.
    expect(Enrolee::where('nin', '12345678901')->count())->toBe(1)
        ->and(Enrolee::where('surname', 'Bello')->count())->toBe(0)
        ->and(Storage::disk('private')->files('photos'))->toBe([]);
});

test('a constraint failure other than the NIN index is not disguised as one', function () {
    // The friendly message is specific to the NIN. Any other database fault must
    // surface as itself: reporting a genuine problem as "this NIN is already
    // registered" would send a user off to re-check a NIN that is perfectly
    // fine, and would hide the fault from whoever has to fix it.
    $user = actingAsProgramUser();

    $service = app(\App\Services\EnroleeService::class);

    // A facility that does not exist. InnoDB reports a foreign key violation
    // with the same SQLSTATE as a duplicate key and names a different index, so
    // this is precisely the case a loose "is it 23000?" check would swallow.
    expect(fn () => $service->createSubmitted(
        ['nin' => '12345678901', 'surname' => 'Bello', 'facility_id' => 9999],
        $user,
        PhotoFixtures::jpeg(200, 200)
    ))->toThrow(
        \Illuminate\Database\QueryException::class,
        'enrolees_facility_id_foreign'
    );
});

/*
|--------------------------------------------------------------------------
| The Submit button stays out of the way when it should
|--------------------------------------------------------------------------
*/

test('a reviewer is not offered a Submit button', function () {
    actingAsReviewer();

    // A reviewer cannot reach the create form at all.
    $this->get(route('enrolees.create'))->assertForbidden();
});

test('an admin editing a submitted record is not offered a Submit button', function () {
    actingAsAdmin();

    $submitted = Enrolee::factory()->submitted()->create();

    $html = $this->get(route('enrolees.edit', $submitted))->assertOk()->getContent();

    expect($html)->not->toContain('data-confirm-submit')
        ->and($html)->toContain('Save changes');
});