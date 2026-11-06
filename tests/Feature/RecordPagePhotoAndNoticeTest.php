<?php

use App\Models\Enrolee;
use Tests\Support\PhotoFixtures;

/*
|--------------------------------------------------------------------------
| The record page: photograph, and the notice after submitting
|--------------------------------------------------------------------------
|
| Two things the record page has to get right, both of which are easy to break
| in a way that still looks fine in a browser:
|
|   1. The photograph can be enlarged, and the enlarged copy still comes from
|      the authorised route. A lightbox is a privacy control if it re-requests
|      the image, and a hole in one if it does not — so the tests below check
|      that there is exactly one URL in the page and that it is the route the
|      policy guards.
|
|   2. The green "Registration submitted" banner belongs to one moment only.
|      Afterwards the record is simply locked, and that is a blue notice saying
|      so. A banner that reappears on every visit is noise; a notice that never
|      appears leaves a reader unable to tell a lock from a bug.
|
*/

/*
|--------------------------------------------------------------------------
| Enlarging the photograph
|--------------------------------------------------------------------------
*/

test('the photograph on the record page can be selected to enlarge it', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->forUser($user)->create();

    $this->put(route('enrolees.update', $enrolee), ['photo' => PhotoFixtures::jpeg(200, 200)]);

    $html = $this->get(route('enrolees.show', $enrolee->refresh()))->assertOk()->getContent();

    expect($html)
        // A real button, so it is reachable by keyboard and announced as
        // actionable, rather than a click handler bolted onto a div.
        ->toMatch('/<button[^>]*data-lightbox[^>]*>/')
        ->toContain('aria-haspopup="dialog"')
        ->toContain('Enlarge the passport photograph');
});

test('the enlarged photograph is requested from the authorised route, not a second URL', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->forUser($user)->create();

    $this->put(route('enrolees.update', $enrolee), ['photo' => PhotoFixtures::jpeg(200, 200)]);

    $html = $this->get(route('enrolees.show', $enrolee->refresh()))->assertOk()->getContent();

    $photoUrl = route('enrolees.photo', $enrolee);

    // The image is loaded from the guarded route...
    expect($html)->toContain($photoUrl)
        // ...and that is the only place the photograph can come from. A second
        // URL, a data attribute carrying a full-size path, or anything pointing
        // into storage or public would each be an unguarded copy of a passport
        // photograph.
        ->and(substr_count($html, $photoUrl))->toBe(1)
        ->and($html)->not->toContain('storage/app')
        ->and($html)->not->toContain('data-lightbox-src');
});

test('a record with no photograph offers no lightbox', function () {
    $user = actingAsProgramUser();
    $enrolee = Enrolee::factory()->forUser($user)->create(['photo_path' => null]);

    $html = $this->get(route('enrolees.show', $enrolee))->assertOk()->getContent();

    expect($html)
        ->toContain('No photograph on file')
        ->not->toContain('data-lightbox');
});

test('the script and stylesheet that implement the lightbox are shipped', function () {
    // The button above is inert without these, and nothing else in the test
    // suite would notice: a dead enlarge button still renders a valid page.
    $script = file_get_contents(public_path('js/app.js'));
    $style = file_get_contents(public_path('css/app.css'));

    expect($script)
        ->toContain('function initPhotoLightbox')
        ->toContain('[data-lightbox]')
        // The enlarged copy's source comes off the thumbnail on the page, which
        // is what keeps it on the authorised route.
        ->toContain('enlarged.src = thumbnail')
        ->toContain("'Escape'")
        ->and($style)
        ->toContain('.lightbox')
        // Without this the overlay's own display:flex would beat the hidden
        // attribute and leave the overlay covering the page after it closed.
        ->toContain('.lightbox[hidden]');
});

/*
|--------------------------------------------------------------------------
| The notice after submitting
|--------------------------------------------------------------------------
*/

test('the green submission banner is shown on the request that follows submitting', function () {
    PhotoFixtures::fakeDisk();

    actingAsProgramUser();

    // One payload, two records: building it twice would try to create a second
    // LGA called Auyo, which the database rightly refuses. The second record
    // reuses the same location chain and differs only in its NIN.
    $payload = $this->validEnroleePayload(['photo' => PhotoFixtures::jpeg(200, 200)]);

    $this->post(route('enrolees.submit-new'), $payload)
        ->assertRedirect(route('enrolees.show', Enrolee::sole()));

    // Following the redirect is the request that matters: this is the page the
    // user lands on immediately after pressing Submit.
    $second = array_merge($payload, [
        'nin' => '12345678902',
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]);

    $html = $this->followingRedirects()
        ->post(route('enrolees.submit-new'), $second)
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Registration submitted. This record is now locked.')
        // Said once. The lock is what the user has just caused by acting, so
        // announcing it as well reads as a problem with what they did.
        ->not->toContain('This record is locked.');
});

test('a later visit shows the blue locked notice and no submission banner', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();

    $this->post(route('enrolees.submit-new'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]));

    $enrolee = Enrolee::sole();

    // The first GET is the landing page from the redirect, and carries the green
    // banner. The second is the next visit, which is what this test is about: by
    // then nothing in the session is carrying the message any more.
    $landing = $this->get(route('enrolees.show', $enrolee))->assertOk()->getContent();

    expect($landing)->toContain('Registration submitted');

    $later = $this->get(route('enrolees.show', $enrolee))->assertOk()->getContent();

    expect($later)
        ->toContain('This record is locked.')
        ->toContain('can no longer be edited by you')
        ->not->toContain('Registration submitted');
});

test('the blue notice survives several later visits', function () {
    PhotoFixtures::fakeDisk();

    $user = actingAsProgramUser();

    $this->post(route('enrolees.submit-new'), $this->validEnroleePayload([
        'photo' => PhotoFixtures::jpeg(200, 200),
    ]));

    $enrolee = Enrolee::sole();

    // Two requests in a row: if the flash were not expiring, or the notice were
    // built from the same key, one of these two would show the wrong one.
    $this->get(route('enrolees.show', $enrolee))->assertOk();
    $html = $this->get(route('enrolees.show', $enrolee))->assertOk()->getContent();

    expect($html)->toContain('This record is locked.')
        ->not->toContain('Registration submitted');
});

test('a reviewer opening a submitted record sees the locked notice, never the banner', function () {
    $reviewer = actingAsReviewer();

    $enrolee = Enrolee::factory()
        ->forProgram($reviewer->program)
        ->submitted()
        ->create();

    // A reviewer never submits, so a green "Registration submitted" here would
    // be a message about an action they did not take.
    $html = $this->get(route('enrolees.show', $enrolee))->assertOk()->getContent();

    expect($html)
        ->toContain('This record is locked.')
        ->not->toContain('Registration submitted');
});

test('an ordinary save does not produce the submission banner', function () {
    actingAsProgramUser();

    $this->post(route('enrolees.store'), ['surname' => 'bello'])->assertRedirect();

    $enrolee = Enrolee::sole();

    $html = $this->get(route('enrolees.show', $enrolee))->assertOk()->getContent();

    // The draft's own confirmation is a separate flash key, and a draft is not
    // locked, so neither message belongs here.
    expect($html)
        ->not->toContain('Registration submitted')
        ->not->toContain('This record is locked.');
});