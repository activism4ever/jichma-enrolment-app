<?php

use App\Models\Enrolee;
use Tests\Support\PhotoFixtures;

/*
|--------------------------------------------------------------------------
| Guards on the routes that must not be reachable
|--------------------------------------------------------------------------
|
| Three requests that are easy to leave unguarded, and that a policy change or a
| stray @can in a template would quietly reopen:
|
|   1. GET /enrolees/{id}/edit on a *submitted* record. The record page hides
|      the Edit button for a Program User, but the URL is guessable — the id of
|      a record they just submitted, for instance — so the guard has to live in
|      the policy rather than in the markup.
|   2. GET /enrolees/{id}/photo for a record belonging to somebody else. Same
|      reasoning: the photograph is the most revealing thing on the page, and
|      the record page is not the only way to reach it.
|   3. The same photograph route with no session at all, which must send the
|      visitor to the login screen rather than answering.
|
| Each test drives the request directly rather than following a link, because
| the point is the response to the request, not whether the page offered it.
|
*/

test('a program user is refused the edit form of a submitted record they created', function () {
    $user = actingAsProgramUser();

    // Submitted *and* created by this user, so neither clause of the policy can
    // be passing for the wrong reason: only the status is what refuses them.
    $submitted = Enrolee::factory()
        ->forUser($user)
        ->submitted()
        ->create();

    $this->get(route('enrolees.edit', $submitted))->assertForbidden();

    // The record page stays readable, so a refusal here is a lock rather than
    // an attempt to hide that the registration exists.
    $this->get(route('enrolees.show', $submitted))
        ->assertOk()
        ->assertSee('This record is locked');
});

test('the photograph route is refused for another program user', function () {
    PhotoFixtures::fakeDisk();

    $owner = actingAsProgramUser();
    $draft = Enrolee::factory()->forUser($owner)->create();

    $this->put(route('enrolees.update', $draft), ['photo' => PhotoFixtures::jpeg(200, 200)])
        ->assertRedirect();

    expect($draft->refresh()->hasPhoto())->toBeTrue();

    // A second user in the same program, so the refusal is about authorship
    // rather than about the program: a Program User's visibility is
    // created_by *and* program_id, and only the first one differs here.
    actingAsProgramUser($owner->program);

    $this->get(route('enrolees.photo', $draft))
        ->assertForbidden();

    // The owner is still served the photograph, which is what makes the 403
    // above an authorisation decision rather than a broken route.
    $this->actingAs($owner)
        ->get(route('enrolees.photo', $draft))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

test('the photograph route sends a signed-out visitor to the login screen', function () {
    PhotoFixtures::fakeDisk();

    $owner = actingAsProgramUser();
    $draft = Enrolee::factory()->forUser($owner)->create();

    $this->put(route('enrolees.update', $draft), ['photo' => PhotoFixtures::jpeg(200, 200)]);

    $this->post('/logout');

    // A redirect, not a 403: there is no answer to give to somebody who has not
    // said who they are, and the login screen is where they can start. 403 here
    // would be both wrong for them and a dead end.
    $response = $this->get(route('enrolees.photo', $draft));

    $response->assertRedirect(route('login'));

    // Nothing image-shaped came back with the redirect. Checked for the JPEG
    // start-of-image marker rather than "the body is not long", because a short
    // body of the wrong bytes is exactly the case that would slip through.
    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($response->getContent())->not->toContain("\xFF\xD8\xFF");
});