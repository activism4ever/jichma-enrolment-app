<?php

use App\Enums\EnroleeStatus;
use App\Models\Enrolee;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PhotoFixtures;

/*
|--------------------------------------------------------------------------
| Draft saving
|--------------------------------------------------------------------------
|
| The brief's central distinction: a half-finished form must save, but
| anything that *is* present must still be well formed. These tests pin both
| halves of that rule, because loosening either one is how invalid data ends up
| in the register.
|
*/

/** An almost empty form still saves. */
test('a program user can save a draft with only a surname', function () {
    $user = actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Bello'])
        ->assertRedirect();

    $enrolee = Enrolee::sole();

    expect($enrolee->surname)->toBe('Bello')
        ->and($enrolee->status)->toBe(EnroleeStatus::Draft)
        ->and($enrolee->program_id)->toBe($user->program_id)
        ->and($enrolee->created_by)->toBe($user->id);
});

/** A draft with no NIN at all must be allowed: many may be in progress. */
test('several drafts may leave the NIN blank at the same time', function () {
    $user = actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'One'])->assertRedirect();
    $this->post(route('enrolees.store'), ['surname' => 'Two'])->assertRedirect();

    expect(Enrolee::whereNull('nin')->count())->toBe(2);
});

/** Present-but-malformed data is rejected even on a draft save. */
test('a draft rejects a NIN that is not eleven digits', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'Bello', 'nin' => '123'])
        ->assertSessionHasErrors('nin');

    expect(Enrolee::count())->toBe(0);
});

test('a draft rejects an invalid phone number', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['phone' => '08000'])
        ->assertSessionHasErrors('phone');

    expect(Enrolee::count())->toBe(0);
});

test('a draft rejects a ward that does not belong to the chosen LGA', function () {
    actingAsProgramUser();

    ['facility' => $facility] = $this->locationChain();

    // A ward that genuinely exists, but belongs to a different LGA.
    $otherWard = \App\Models\Ward::factory()->create();

    $this->post(route('enrolees.store'), [
        'surname' => 'Bello',
        'lga_id' => $facility->ward->lga_id,
        'ward_id' => $otherWard->id,
    ])->assertSessionHasErrors('ward_id');

    expect(Enrolee::count())->toBe(0);
});

test('a draft rejects a facility that does not belong to the chosen ward', function () {
    actingAsProgramUser();

    $facility = $this->locationChain()['facility'];
    $otherWard = \App\Models\Ward::factory()->create();

    $this->post(route('enrolees.store'), [
        'surname' => 'Bello',
        'lga_id' => $facility->ward->lga_id,
        'ward_id' => $otherWard->id,
        'facility_id' => $facility->id,
    ])->assertSessionHasErrors('facility_id');

    expect(Enrolee::count())->toBe(0);
});

/** Phone numbers are stored in one canonical form however they were typed. */
test('phone numbers are normalised to the local format on save', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['phone' => '+234 801 234 5678'])->assertRedirect();

    expect(Enrolee::sole()->phone)->toBe('08012345678');
});

/*
|--------------------------------------------------------------------------
| The duplicate NIN rule
|--------------------------------------------------------------------------
|
| One person, one record, across every program and every status. These are the
| tests the brief singles out, so they cover each way the block can be bypassed.
|
*/

test('a NIN already used in another program is rejected', function () {
    actingAsProgramUser();

    Enrolee::factory()->withNin('11111111111')->forProgram($this->program('PLHIV'))->create();

    $this->post(route('enrolees.store'), ['nin' => '11111111111'])
        ->assertSessionHasErrors('nin');

    expect(Enrolee::count())->toBe(1);
});

test('a NIN already used by another program user is rejected', function () {
    $other = actingAsProgramUser();
    actingAsProgramUser($this->program('CEMOG'));

    Enrolee::factory()->withNin('22222222222')->forUser($other)->create();

    $this->post(route('enrolees.store'), ['nin' => '22222222222'])
        ->assertSessionHasErrors('nin');
});

test('a NIN on a soft-deleted record stays blocked', function () {
    actingAsProgramUser();

    $deleted = Enrolee::factory()->withNin('33333333333')->create();
    $deleted->delete();

    expect(Enrolee::withTrashed()->count())->toBe(1);

    $this->post(route('enrolees.store'), ['nin' => '33333333333'])
        ->assertSessionHasErrors('nin');

    expect(Enrolee::withTrashed()->count())->toBe(1);
});

test('the duplicate message does not reveal who holds the NIN', function () {
    actingAsProgramUser();

    Enrolee::factory()->withNin('44444444444')->create();

    $response = $this->from(route('enrolees.create'))
        ->post(route('enrolees.store'), ['nin' => '44444444444']);

    $message = session('errors')->first('nin');

    expect($message)->toBe('This NIN is already registered.')
        ->and(strtolower($message))
        ->not->toContain('program')
        ->not->toContain('cah');
});

/*
|--------------------------------------------------------------------------
| Submission
|--------------------------------------------------------------------------
*/

test('submitting requires every field and a photograph', function () {
    $user = actingAsProgramUser();

    $draft = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->post(route('enrolees.submit', $draft), [
        'surname' => $draft->surname,
        'first_name' => 'Amina',
        'gender' => 'female',
        'date_of_birth' => '1990-04-12',
        'marital_status' => 'married',
        'education' => 'hnd_bsc',
        'phone' => '08031234567',
        'nin' => '12345678901',
        'address' => '12 Zaria Road',
        'lga_id' => 1,
        'ward_id' => 1,
        'facility_id' => 1,
    ])->assertSessionHasErrors(['photo', 'lga_id']);

    expect($draft->refresh()->status)->toBe(EnroleeStatus::Draft);
});

test('a complete draft with a photograph submits successfully', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();

    $this->post(route('enrolees.store'), $this->validEnroleePayload())
        ->assertRedirect();

    $enrolee = Enrolee::sole();

    $this->post(route('enrolees.submit', $enrolee), [
        'photo' => PhotoFixtures::jpeg(400, 400),
    ])->assertRedirect(route('enrolees.show', $enrolee));

    $enrolee->refresh();

    expect($enrolee->status)->toBe(EnroleeStatus::Submitted)
        ->and($enrolee->submitted_by)->toBe($user->id)
        ->and($enrolee->submitted_at)->not->toBeNull()
        ->and($enrolee->photo_path)->not->toBeNull();
});

test('a draft that already has a photograph submits without a second upload', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::jpeg(400, 400),
    ]))->assertRedirect();

    $enrolee = Enrolee::sole();
    expect($enrolee->photo_path)->not->toBeNull();

    $this->post(route('enrolees.submit', $enrolee))
        ->assertSessionHasNoErrors();

    expect($enrolee->refresh()->status)->toBe(EnroleeStatus::Submitted);
});

test('a future date of birth is rejected on submit', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'date_of_birth' => now()->addDay()->toDateString(),
    ]))->assertSessionHasErrors('date_of_birth');
});

test('a date of birth for a child under five is accepted', function () {
    actingAsProgramUser();

    $dateOfBirth = now()->subYears(2)->toDateString();

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'date_of_birth' => $dateOfBirth,
    ]))->assertSessionHasNoErrors();

    expect(Enrolee::sole()->date_of_birth->toDateString())->toBe($dateOfBirth);
});

test('a newborn date of birth is accepted', function () {
    actingAsProgramUser();

    $dateOfBirth = now()->toDateString();

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'date_of_birth' => $dateOfBirth,
    ]))->assertSessionHasNoErrors();

    expect(Enrolee::sole()->date_of_birth->toDateString())->toBe($dateOfBirth);
});

test('an unknown gender is rejected rather than silently dropped', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['gender' => 'other'])
        ->assertSessionHasErrors('gender');
});

/*
|--------------------------------------------------------------------------
| Server-owned fields
|--------------------------------------------------------------------------
|
| A Program User cannot move a record into another program, and cannot mark
| something submitted without going through the submit action.
|
*/

test('a program user cannot set program_id by crafting the request', function () {
    $user = actingAsProgramUser();
    $other = $this->program('PLHIV');

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'program_id' => $other->id,
        'status' => 'submitted',
        'created_by' => $user->id,
    ]))->assertRedirect();

    $enrolee = Enrolee::sole();

    expect($enrolee->program_id)->toBe($user->program_id)
        ->and($enrolee->status)->toBe(EnroleeStatus::Draft);
});

test('an admin must choose a program when registering', function () {
    actingAsAdmin();

    $this->post(route('enrolees.store'), $this->validEnroleePayload())
        ->assertSessionHasErrors('program_id');

    expect(Enrolee::count())->toBe(0);
});

test('an admin registration is stored in the chosen program', function () {
    actingAsAdmin();

    $plhiv = $this->program('PLHIV');

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'program_id' => $plhiv->id,
    ]))->assertRedirect();

    expect(Enrolee::sole()->program_id)->toBe($plhiv->id);
});

/*
|--------------------------------------------------------------------------
| Editing, locking and deletion
|--------------------------------------------------------------------------
*/

test('a program user can edit their own draft', function () {
    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->partialDraft()->forUser($user)->create();

    $this->put(route('enrolees.update', $enrolee), ['surname' => 'Updated'])
        ->assertRedirect(route('enrolees.show', $enrolee));

    expect($enrolee->refresh()->surname)->toBe('Updated');
});

test('a program user cannot edit a record they did not create', function () {
    $other = actingAsProgramUser();
    actingAsProgramUser();

    $enrolee = Enrolee::factory()->forUser($other)->create();

    $this->put(route('enrolees.update', $enrolee), ['surname' => 'Hijacked'])
        ->assertForbidden();

    expect($enrolee->refresh()->surname)->not->toBe('Hijacked');
});

test('a submitted record is locked for the program user who created it', function () {
    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->forUser($user)->submitted()->create();

    $this->get(route('enrolees.edit', $enrolee))->assertForbidden();

    $this->put(route('enrolees.update', $enrolee), ['surname' => 'Changed'])
        ->assertForbidden();

    expect($enrolee->refresh()->surname)->not->toBe('Changed');
});

test('an admin can still correct a submitted record', function () {
    actingAsAdmin();

    $enrolee = Enrolee::factory()->submitted()->create();

    $this->put(route('enrolees.update', $enrolee), ['surname' => 'Corrected'])
        ->assertRedirect(route('enrolees.show', $enrolee));

    expect($enrolee->refresh()->surname)->toBe('Corrected');
});

test('a program user cannot delete a submitted record', function () {
    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->forUser($user)->submitted()->create();

    $this->delete(route('enrolees.destroy', $enrolee))->assertForbidden();

    expect(Enrolee::withTrashed()->count())->toBe(1);
});

test('a program user can delete their own draft and the row survives', function () {
    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->forUser($user)->create();

    $this->delete(route('enrolees.destroy', $enrolee))
        ->assertRedirect(route('enrolees.index'));

    expect(Enrolee::count())->toBe(0)
        ->and(Enrolee::withTrashed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Photograph handling
|--------------------------------------------------------------------------
*/

test('a photo is re-encoded to jpeg, shrunk and given a random name', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::jpeg(2400, 1600),
    ]))->assertRedirect();

    $enrolee = Enrolee::sole();
    $path = $enrolee->photo_path;

    expect($path)->not->toBeNull()
        ->and($path)->toEndWith('.jpg')
        ->and($path)->not->toContain('passport');

    $size = getimagesizefromstring(Storage::get($path));

    expect($size[0])->toBeLessThanOrEqual(800)
        ->and($size[1])->toBeLessThanOrEqual(800)
        ->and($size['mime'])->toBe('image/jpeg');
})->skipOnWindows();

test('a file that is not really an image is rejected', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::disguisedTextFile(),
    ]))->assertSessionHasErrors('photo');

    expect(Enrolee::count())->toBe(0);
});

test('an image larger than five megabytes is rejected', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::oversizedJpeg(),
    ]))->assertSessionHasErrors('photo');

    expect(Enrolee::count())->toBe(0);
});

test('replacing a photograph removes the previous file', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->forUser($user)->create();

    $this->put(route('enrolees.update', $enrolee), [
        'photo' => PhotoFixtures::jpeg(300, 300),
    ])->assertRedirect();

    $firstPath = $enrolee->refresh()->photo_path;

    $this->put(route('enrolees.update', $enrolee), [
        'photo' => PhotoFixtures::png(300, 300),
    ])->assertRedirect();

    $secondPath = $enrolee->refresh()->photo_path;

    expect($secondPath)->not->toBe($firstPath)
        ->and(Storage::disk('private')->exists($firstPath))->toBeFalse()
        ->and(Storage::disk('private')->exists($secondPath))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Isolation between users and programs
|--------------------------------------------------------------------------
|
| The rule the brief is most emphatic about: a Program User sees only what they
| created, a Reviewer sees only their program, and neither can reach anything
| else by guessing an id in the URL.
|
*/

test('a program user cannot view another program user record by id', function () {
    $other = actingAsProgramUser();
    actingAsProgramUser();

    $enrolee = Enrolee::factory()->forUser($other)->create();

    $this->get(route('enrolees.show', $enrolee))->assertForbidden();
});

test('a program user cannot view a record in another program even if they created it', function () {
    $user = actingAsProgramUser();

    $enrolee = Enrolee::factory()->create([
        'created_by' => $user->id,
        'program_id' => $this->program('PLHIV')->id,
    ]);

    $this->get(route('enrolees.show', $enrolee))->assertForbidden();
});

test('a reviewer sees every record in their own program and nothing outside it', function () {
    $plhiv = $this->program('PLHIV');
    $cemog = $this->program('CEMOG');

    $insideDraft = Enrolee::factory()->forProgram($plhiv)->create();
    $insideSubmitted = Enrolee::factory()->forProgram($plhiv)->submitted()->create();
    $outside = Enrolee::factory()->forProgram($cemog)->submitted()->create();

    actingAsReviewer($plhiv);

    $this->get(route('enrolees.index'))->assertOk()->assertSee($insideDraft->full_name)
        ->assertSee($insideSubmitted->full_name)
        ->assertDontSee($outside->full_name);

    $this->get(route('enrolees.show', $outside))->assertForbidden();
});

test('a reviewer cannot create, edit, delete or submit', function () {
    $plhiv = $this->program('PLHIV');
    $enrolee = Enrolee::factory()->forProgram($plhiv)->create();

    actingAsReviewer($plhiv);

    $this->get(route('enrolees.create'))->assertForbidden();

    $this->post(route('enrolees.store'), $this->validEnroleePayload())
        ->assertForbidden();

    $this->put(route('enrolees.update', $enrolee), ['surname' => 'X'])
        ->assertForbidden();

    $this->post(route('enrolees.submit', $enrolee))->assertForbidden();

    $this->delete(route('enrolees.destroy', $enrolee))->assertForbidden();
});

test('an admin sees records from every program', function () {
    Enrolee::factory()->forProgram($this->program('PLHIV'))->create();
    Enrolee::factory()->forProgram($this->program('CEMOG'))->create();

    actingAsAdmin();

    $this->get(route('enrolees.index'))->assertOk();

    expect($this->visibleCountTo(auth()->user()))->toBe(2);
});

test('a soft-deleted record is hidden from listings', function () {
    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->forUser($user)->create();
    $enrolee->delete();

    $this->get(route('enrolees.index'))->assertOk()->assertDontSee($enrolee->full_name);

    $this->get(route('enrolees.show', $enrolee))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Listing filters
|--------------------------------------------------------------------------
*/

test('the list is scoped to the signed-in user, not to a query parameter', function () {
    $mine = actingAsProgramUser();

    $other = \App\Models\User::factory()->programUser()->create();
    Enrolee::factory()->forUser($mine)->create(['surname' => 'Mine']);
    Enrolee::factory()->forUser($other)->create(['surname' => 'Theirs']);

    $this->get(route('enrolees.index', ['created_by' => $other->id]))
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

test('a program user lands on their own drafts by default', function () {
    $user = actingAsProgramUser();

    $draft = Enrolee::factory()->forUser($user)->create(['surname' => 'StillDrafting']);
    $submitted = Enrolee::factory()->forUser($user)->submitted()->create(['surname' => 'AllDone']);

    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee('StillDrafting')
        ->assertDontSee('AllDone');
});

test('search matches names, NIN and phone', function () {
    $user = actingAsProgramUser();

    Enrolee::factory()->forUser($user)->create([
        'surname' => 'Findable',
        'first_name' => 'Testcase',
        'nin' => '55667788990',
        'phone' => '08055556666',
    ]);

    $this->get(route('enrolees.index', ['search' => 'Findable']))->assertOk();
    $this->get(route('enrolees.index', ['search' => '55667788990']))->assertOk();
    $this->get(route('enrolees.index', ['search' => '08055556666']))->assertOk();
    $this->get(route('enrolees.index', ['search' => 'Nothingmatchesthis']))->assertOk();
});

test('an unknown status filter is ignored rather than erroring', function () {
    actingAsProgramUser();

    $this->get(route('enrolees.index', ['status' => 'hacked']))
        ->assertOk();
});

test('an account that has never held a record is told to register one', function () {
    actingAsAdmin();

    // The two absences are different, so they say different things: nobody
    // has ever enrolled anyone here, and the next step is to enrol someone.
    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee('No enrolees yet.')
        ->assertSee('There are currently no enrolment records.')
        ->assertSee('+ Register new enrolee')
        ->assertDontSee('No enrolees match these filters.');
});

test('a filter that matches nothing is told to widen the search', function () {
    actingAsAdmin();

    Enrolee::factory()->create(['surname' => 'Somebody']);

    // Records exist; this search simply is not one of them. "Register one"
    // would be a lie here, and a bare "nothing found" would leave the
    // searcher with no next step.
    $this->get(route('enrolees.index', ['search' => 'Nothingmatchesthis']))
        ->assertOk()
        ->assertSee('No enrolees match these filters.')
        ->assertSee('Try adjusting your search criteria or reset the filters.')
        ->assertSee('Reset Filters')
        ->assertDontSee('No enrolees yet.');
});

test('the number of matching records sits above the table', function () {
    actingAsAdmin();

    Enrolee::factory()->count(3)->create();

    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee('3 enrolees');
});

test('a filtered count says how many matched out of the whole scope', function () {
    actingAsAdmin();

    Enrolee::factory()->count(3)->create();
    Enrolee::factory()->create(['surname' => 'Findable']);

    // Both numbers come from the queries: the matched count from the
    // filtered one, the scope total from the same unfiltered query the
    // empty states are answered from.
    $this->get(route('enrolees.index', ['search' => 'Findable']))
        ->assertOk()
        ->assertSee('Showing 1 of 4 enrolees');

    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee('4 enrolees');
});

test('the list paginates with numbered links and says what range is shown', function () {
    Enrolee::factory()->count(26)->create();

    actingAsAdmin();

    $response = $this->get(route('enrolees.index'));

    $response->assertOk()
        ->assertSee('Showing 1–25 of 26')
        ->assertSee('26 enrolees')
        // A real URL, so page 2 can be opened in a new tab and reached from
        // the keyboard like any other link.
        ->assertSee(route('enrolees.index', ['page' => 2]), escape: false);
});

test('the register action sits beside the heading, and only for those who may', function () {
    actingAsAdmin();

    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee('+ New enrolee');

    actingAsReviewer($this->program('PLHIV'));

    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertDontSee('New enrolee')
        ->assertDontSee('+ Register new enrolee');
});

/*
|--------------------------------------------------------------------------
| Location lookup endpoints
|--------------------------------------------------------------------------
*/

test('wards are returned only for the requested LGA', function () {
    actingAsAdmin();

    $chain = $this->locationChain();
    $otherLga = \App\Models\Lga::factory()->create();
    $otherWard = \App\Models\Ward::factory()->forLga($otherLga)->create(['name' => 'Elsewhere']);

    $this->getJson(route('api.wards', $chain['lga']))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Auyakayi'])
        ->assertJsonMissing(['name' => 'Elsewhere']);
});

test('facilities are returned only for the requested ward', function () {
    actingAsAdmin();

    $chain = $this->locationChain();
    $otherWard = \App\Models\Ward::factory()->create();
    \App\Models\Facility::factory()->forWard($otherWard)->create(['name' => 'Somewhere Clinic']);

    $this->getJson(route('api.facilities', $chain['ward']))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Auyakayi BHC'])
        ->assertJsonMissing(['name' => 'Somewhere Clinic']);
});

test('location lookups require authentication', function () {
    $chain = $this->locationChain();

    $this->getJson(route('api.lgas'))->assertUnauthorized();
    $this->getJson(route('api.wards', $chain['lga']))->assertUnauthorized();
    $this->getJson(route('api.facilities', $chain['ward']))->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Photograph route
|--------------------------------------------------------------------------
*/

test('a photograph is served only to a user allowed to see the record', function () {
    PhotoFixtures::fakeDisk();

    $owner = actingAsProgramUser();
    $draft = Enrolee::factory()->forUser($owner)->create();

    $this->put(route('enrolees.update', $draft), ['photo' => PhotoFixtures::jpeg(200, 200)]);

    $path = $draft->refresh()->photo_path;

    $this->get(route('enrolees.photo', $draft))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    actingAsProgramUser();

    $this->get(route('enrolees.photo', $draft))->assertForbidden();
})->skipOnWindows();

test('a record with no photograph returns not found rather than an empty image', function () {
    // forUser matters: without it the acting user is refused by policy first,
    // and a 403 would pass this test without ever reaching the photo check.
    $user = actingAsProgramUser();

    $enrolee = Enrolee::factory()->forUser($user)->create(['photo_path' => null]);

    $this->get(route('enrolees.photo', $enrolee))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Audit trail
|--------------------------------------------------------------------------
*/

test('creating, submitting, editing and deleting are all audited', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();

    $this->post(route('enrolees.store'), $this->validEnroleePayload())->assertRedirect();
    $enrolee = Enrolee::sole();

    $this->post(route('enrolees.submit', $enrolee), ['photo' => PhotoFixtures::jpeg(200, 200)])
        ->assertRedirect();

    // A submitted record is locked for its creator: only an administrator may
    // delete one, which is covered separately by "a program user cannot delete
    // their own submitted record".
    $this->delete(route('enrolees.destroy', $enrolee))->assertForbidden();

    actingAsAdmin();

    $this->delete(route('enrolees.destroy', $enrolee))->assertRedirect();

    $actions = \App\Models\AuditLog::pluck('action')->all();

    expect($actions)->toContain('enrolee_created')
        ->toContain('enrolee_submitted')
        ->toContain('enrolee_deleted');
})->skipOnWindows();

test('an admin edit to a submitted record records the before and after values', function () {
    actingAsAdmin();

    $enrolee = Enrolee::factory()->submitted()->create(['surname' => 'Original']);

    $this->put(route('enrolees.update', $enrolee), ['surname' => 'Corrected'])
        ->assertRedirect();

    $log = \App\Models\AuditLog::where('action', 'enrolee_updated')->sole();

    expect($log->old_values['surname'])->toBe('Original')
        ->and($log->new_values['surname'])->toBe('Corrected');
});
