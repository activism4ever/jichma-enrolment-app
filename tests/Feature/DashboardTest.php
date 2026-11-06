<?php

use App\Models\Enrolee;
use App\Models\Program;
use App\Models\User;
use App\Services\EnroleeFilters;

/**
 * Dashboard totals, per role.
 *
 * The point of most of these is not that the number is right, but that it is
 * the *same* right: each count has to match what the enrolee list would show,
 * because a dashboard that disagrees with its own list is how one user ends up
 * inferring another's data from a total.
 *
 * The other half of the file is what the page must *not* contain. Enrolment
 * status is the most tempting thing to put on a dashboard and the least useful:
 * it belongs to the list screen, where a number can be filtered into rows, and
 * on a summary card it is a number nobody can act on.
 */

/**
 * Read the stat cards as label => value.
 *
 * Asserting a number with assertSeeInOrder(['Total', '3']) looks like it proves
 * the card shows 3, but it does not: the card renders value-then-label, so the
 * order assertion is satisfied by any "3" appearing anywhere later in the page,
 * including a digit of the CSRF token. That passes or fails depending on the
 * random token. Pulling the value out of the card itself is both precise and
 * stable.
 *
 * @return array<string, int>
 */
function statCards($response): array
{
    preg_match_all(
        '/stat-value">\s*(\d+)\s*<\/div>\s*<div class="stat-label">\s*([^<]+?)\s*<\/div>/',
        $response->getContent(),
        $matches,
        PREG_SET_ORDER,
    );

    return collect($matches)->mapWithKeys(fn ($m) => [$m[2] => (int) $m[1]])->all();
}

/**
 * Freeze the clock in the middle of a month.
 *
 * The 15th, not "now": a test that runs on the 1st would find that last month
 * and this month are the same length and quietly prove nothing.
 */
function freezeMidMonth(): void
{
    test()->travelTo(now()->startOfMonth()->addDays(14)->setTime(10, 0));
}

test('a Program User sees their total, month and today counts', function () {
    $user = actingAsProgramUser();

    Enrolee::factory()->forUser($user)->count(4)->create();
    Enrolee::factory()->forUser($user)->count(2)->create(['created_at' => now()->startOfMonth()]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();

    expect(statCards($response))->toBe([
        'Total enrolees' => 6,
        'Enrolled this month' => 6,
        'Enrolled today' => 4,
    ]);
});

test('the month and today counts are calendar boundaries, not rolling windows', function () {
    $user = actingAsProgramUser();

    freezeMidMonth();

    // Today, yesterday, earlier this month, and the last day of last month.
    Enrolee::factory()->forUser($user)->count(3)->create();
    Enrolee::factory()->forUser($user)->create(['created_at' => now()->subDay()]);
    Enrolee::factory()->forUser($user)->create(['created_at' => now()->startOfMonth()->addDays(2)]);
    Enrolee::factory()->forUser($user)->create(['created_at' => now()->startOfMonth()->subDay()]);

    expect(statCards($this->get(route('dashboard'))))->toBe([
        'Total enrolees' => 6,
        'Enrolled this month' => 5,
        'Enrolled today' => 3,
    ]);

    // Tomorrow: today empties, the month does not. A rolling 24-hour window
    // would have carried today's three across into it, and a month counted as
    // "last 30 days" would still be carrying the 2nd.
    $this->travelTo(now()->addDay()->setTime(9, 0));

    expect(statCards($this->get(route('dashboard'))))->toBe([
        'Total enrolees' => 6,
        'Enrolled this month' => 5,
        'Enrolled today' => 0,
    ]);
});

test('a Program User total counts every status, because no status is broken out', function () {
    $user = actingAsProgramUser();

    Enrolee::factory()->forUser($user)->draft()->count(3)->create();
    Enrolee::factory()->forUser($user)->submitted()->count(2)->create();

    // A draft is an enrolment record. Leaving it out of the total would make the
    // number disagree with the list, which counts it.
    expect(statCards($this->get(route('dashboard')))['Total enrolees'])->toBe(5);
});

test('a soft-deleted record leaves the totals, as it leaves the list', function () {
    $user = actingAsProgramUser();

    Enrolee::factory()->forUser($user)->count(3)->create();

    Enrolee::first()->delete();

    expect(statCards($this->get(route('dashboard')))['Total enrolees'])->toBe(2);
});

test('a Program User total excludes a colleague records in the same program', function () {
    $program = test()->program('CEMOG');
    $user = actingAsProgramUser($program);

    Enrolee::factory()->forUser($user)->count(2)->create();

    // A colleague in the *same* program, which visibleTo must still exclude.
    $colleague = User::factory()->programUser($program)->create();
    Enrolee::factory()->forUser($colleague)->count(4)->create([
        'created_at' => now()->addMinutes(5), // newer than the user's own,
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    expect(statCards($response)['Total enrolees'])->toBe(2);

    // Nor may the colleague's record appear in the recent list.
    $response->assertDontSee('Zamani');
});

test('a Program User total excludes records from another program', function () {
    $user = actingAsProgramUser(test()->program('CEMOG'));

    Enrolee::factory()->forUser($user)->create();

    $other = User::factory()->programUser(test()->program('VESTILAR'))->create();
    Enrolee::factory()->forUser($other)->count(3)->create();

    expect(statCards($this->get(route('dashboard')))['Total enrolees'])->toBe(1);
});

test('the dashboard totals agree with what the enrolee list reports', function () {
    $user = actingAsProgramUser();

    Enrolee::factory()->forUser($user)->count(4)->create();
    Enrolee::factory()->forUser($user)->draft()->count(2)->create();

    // The exact object EnroleeController@index builds, rather than a count
    // written out again here: the point is that the two screens cannot drift,
    // and a re-count would only test the query twice.
    //
    // Note this is deliberately not the index screen's paginator total. That
    // screen deliberately lands a Program User on their drafts, so its count is
    // meant to be smaller than the number of enrolees that exist.
    $listTotal = (new EnroleeFilters())->queryFor($user)->count();

    expect($listTotal)->toBe(6)
        ->and(statCards($this->get(route('dashboard')))['Total enrolees'])->toBe($listTotal);
});

test('the recent list shows the newest enrolments first', function () {
    $user = actingAsProgramUser();

    $newest = Enrolee::factory()->forUser($user)->create([
        'surname' => 'Newest',
        'created_at' => now()->subMinutes(5),
    ]);

    $older = Enrolee::factory()->forUser($user)->create([
        'surname' => 'Older',
        'created_at' => now()->subDays(3),
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['Newest', 'Older']);
    $response->assertSee(route('enrolees.show', $newest), escape: false);
    $response->assertSee(route('enrolees.show', $older), escape: false);
});

test('the recent list is ordered by enrolment, not by the last edit', function () {
    $user = actingAsProgramUser();

    // An old enrolment corrected this morning. Recent activity, but not a recent
    // enrolment: putting it at the top would answer "what was touched", not
    // "who is new".
    Enrolee::factory()->forUser($user)->create([
        'surname' => 'Corrected',
        'created_at' => now()->subYear(),
        'updated_at' => now()->subMinute(),
    ]);

    Enrolee::factory()->forUser($user)->create([
        'surname' => 'Fresh',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['Fresh', 'Corrected']);
});

test('the recent list is capped at eight', function () {
    $user = actingAsProgramUser();

    // Zero-padded, or "Enrolee1" would be found inside "Enrolee11" and the
    // assertion below would pass for the wrong reason — or fail for no reason.
    collect(range(1, 11))->each(fn ($i) => Enrolee::factory()->forUser($user)->create([
        'surname' => 'Enrolee'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        'created_at' => now()->subMinutes(100 - $i),
    ]));

    $response = $this->get(route('dashboard'));

    $response->assertOk();

    // The eight most recent enrolments, and not the three oldest.
    foreach (range(4, 11) as $i) {
        $response->assertSee('Enrolee'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    foreach (range(1, 3) as $i) {
        $response->assertDontSee('Enrolee'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }
});

test('a record with no name yet is still listed as an enrolment', function () {
    $user = actingAsProgramUser();

    // A real early draft: created and left, before any name was typed.
    Enrolee::factory()->forUser($user)->partialDraft()->create([
        'surname' => null,
        'first_name' => null,
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Unnamed enrolee');
});

test('a Program User with no records is told so plainly', function () {
    actingAsProgramUser();

    // The heading stays, so the empty state reads as "nothing enrolled yet"
    // rather than as a section that failed to load.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Recent enrolees')
        ->assertSee('No enrolees yet.');
});

test('a Program User is offered the three enrolment actions', function () {
    actingAsProgramUser();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Register new enrolee')
        ->assertSee('Browse enrolees')
        ->assertSee('Change password')
        ->assertSee(route('enrolees.create'), escape: false)
        ->assertSee(route('enrolees.index'), escape: false)
        ->assertSee(route('password.edit'), escape: false);
});

test('a Reviewer sees program totals but no register affordance', function () {
    $program = test()->program('CEMOG');
    $reviewer = actingAsReviewer($program);

    // Two created by one user, one by another: a reviewer sees all of them.
    $creator = User::factory()->programUser($program)->create();
    Enrolee::factory()->forUser($creator)->count(3)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();

    expect(statCards($response)['Total enrolees'])->toBe(3);

    $response->assertSee('Browse enrolees')
        ->assertSee('Change password')
        ->assertSee('Your account is read-only');

    // Nothing that would act.
    $response->assertDontSee('Register new enrolee')
        ->assertDontSee(route('enrolees.create'), escape: false);
});

test('a Reviewer sees no records from another program', function () {
    actingAsReviewer(test()->program('CEMOG'));

    Enrolee::factory()->forUser(User::factory()->programUser(test()->program('CEMOG'))->create())->create();

    Enrolee::factory()->forUser(User::factory()->programUser(test()->program('VESTILAR'))->create())->count(4)->create();

    expect(statCards($this->get(route('dashboard')))['Total enrolees'])->toBe(1);
});

test('an Admin total spans every program', function () {
    actingAsAdmin();

    Enrolee::factory()->count(3)->create();
    Enrolee::factory()->count(2)->create();

    expect(statCards($this->get(route('dashboard')))['Total enrolees'])->toBe(5);
});

test('an Admin sees which program each recent enrolee belongs to', function () {
    actingAsAdmin();

    Enrolee::factory()->create([
        'surname' => 'Across',
        'program_id' => test()->program('CEMOG')->id,
    ]);

    Enrolee::factory()->create([
        'surname' => 'Programs',
        'program_id' => test()->program('VESTILAR')->id,
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['Across', 'CEMOG']);
    $response->assertSeeInOrder(['Programs', 'VESTILAR']);
});

test('only an Admin is shown a program column in the recent list', function () {
    $program = test()->program('CEMOG');

    Enrolee::factory()->forUser(actingAsProgramUser($program))->create();

    // Every row would carry the same one program: a column of identical values is
    // noise pretending to be information. The column belongs to a view that spans
    // programs, which is the administrator's.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('<th>Program</th>', escape: false);

    $this->actingAs(actingAsAdmin())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<th>Program</th>', escape: false);
});

test('an Admin is offered the register and browse actions', function () {
    actingAsAdmin();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Register new enrolee')
        ->assertSee('Browse enrolees')
        ->assertSee('Change password');
});

test('no role is shown enrolment status statistics', function () {
    $program = test()->program('CEMOG');

    Enrolee::factory()->count(2)->create();

    foreach ([actingAsAdmin(), actingAsProgramUser($program), actingAsReviewer($program)] as $user) {
        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        // The statuses this application has, plus the words a status summary
        // would be dressed up as. A total that cannot be filtered into rows is
        // not something the enrolee list can corroborate.
        foreach (['Drafts', 'Submitted', 'Verified', 'Rejected', 'Pending'] as $status) {
            $response->assertDontSee($status);
        }
    }
});

test('no role is shown administrative tables or a shortcuts list', function () {
    $program = test()->program('CEMOG');

    foreach ([actingAsAdmin(), actingAsProgramUser($program), actingAsReviewer($program)] as $user) {
        $response = $this->actingAs($user)->get(route('dashboard'));

        // The access card does name the signed-in user's own role, which is not
        // a metric; what must not appear is a table of counts per role.
        $response->assertOk()
            ->assertDontSee('Enrolees by program')
            ->assertDontSee('Active users')
            ->assertDontSee('Shortcuts');
    }
});

test('the dashboard keeps the access card and password link for every role', function () {
    $program = test()->program('CEMOG');

    foreach ([actingAsAdmin(), actingAsProgramUser($program), actingAsReviewer($program)] as $user) {
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your access')
            ->assertSee($user->role->label())
            ->assertSee(route('password.edit'), escape: false);
    }
});

test('a program with a distinct name is shown as code and name', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'HIV', 'name' => 'HIV counselling']);

    Enrolee::factory()->create(['program_id' => $program->id]);

    // Only reachable through the recent list's program column, which is the one
    // place a dashboard row can show a program the reader might not know.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('HIV — HIV counselling');
});

test('the access card names the program by code', function () {
    $user = actingAsProgramUser(Program::factory()->create(['code' => 'HIV', 'name' => 'HIV counselling']));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('HIV')
        // One role, one program: naming the program twice on one screen says the
        // same thing in two places.
        ->assertDontSee('HIV counselling');
});