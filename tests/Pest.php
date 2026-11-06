<?php

use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Global test helpers.
 *
 * `actingAsAdmin` exists because almost every authorisation test needs one
 * admin, and the alternative is five lines of factory boilerplate repeated
 * dozens of times.
 */
function actingAsAdmin(array $attributes = [])
{
    $admin = \App\Models\User::factory()->admin()->create($attributes);

    test()->actingAs($admin);

    return $admin;
}

/** A Program User in a specific program, already signed in. */
function actingAsProgramUser($program = null, array $attributes = [])
{
    $program ??= test()->program('CEMOG');

    $user = \App\Models\User::factory()->programUser($program)->create($attributes);

    test()->actingAs($user);

    return $user;
}

/** A Reviewer for a specific program, already signed in. */
function actingAsReviewer($program = null, array $attributes = [])
{
    $program ??= test()->program('PLHIV');

    $user = \App\Models\User::factory()->reviewer($program)->create($attributes);

    test()->actingAs($user);

    return $user;
}

/** A signed-in user that is NOT active, to prove the block works. */
function actingAsDeactivatedUser($program = null)
{
    $program ??= test()->program('CEMOG');

    $user = \App\Models\User::factory()
        ->programUser($program)
        ->inactive()
        ->create();

    test()->actingAs($user);

    return $user;
}