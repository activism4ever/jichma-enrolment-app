<?php

/*
|--------------------------------------------------------------------------
| Search boxes and browser autocomplete
|--------------------------------------------------------------------------
|
| Every filter form on the site has a field named search, and a browser that
| has been used before will happily fill it with a name or an address from a
| completely unrelated form. That is both wrong and, on the export screen,
| quietly narrowing: the count above the button is computed from whatever is in
| the box, so a stray autofill is a filter nobody chose.
|
| The login and change-password pages are the deliberate opposite: they ask for a
| password, so they must keep current-password and new-password, which is what
| lets a password manager offer the right entry and offers to generate a new one.
|
*/

test('every search box opts out of browser autofill', function () {
    actingAsAdmin();

    foreach ([
        route('admin.exports.index'),
        route('enrolees.index'),
        route('admin.users.index'),
    ] as $url) {
        $response = $this->get($url)->assertOk();

        // Matched within the one tag rather than anywhere in the page, so a
        // stray autocomplete="off" on some other input cannot satisfy this.
        expect($response->getContent())
            ->toMatch('/type="search"[^>]*name="search"[^>]*autocomplete="off"/');
    }
});

test('the password fields on the login and change-password pages are unaffected', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('autocomplete="current-password"', escape: false);

    actingAsAdmin();

    $this->get(route('password.edit'))
        ->assertOk()
        ->assertSee('autocomplete="current-password"', escape: false)
        ->assertSee('autocomplete="new-password"', escape: false);
});