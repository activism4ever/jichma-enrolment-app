<?php

use App\Models\Enrolee;
use App\Models\Lga;
use App\Models\User;
use App\Models\Ward;

/**
 * The Programme Summary screens.
 *
 * Two truths are being tested here, over and over:
 *
 *   1. The numbers are the *same* numbers the enrolee list builds. Every query
 *      behind these pages operates on EnroleeFilters::queryFor() / the same
 *      visibleTo scoping, so a summary can never show a row the list would
 *      refuse, or a total that disagrees with it.
 *   2. The drill-downs add up. A programme's LGA table sums to the headline,
 *      a ward table sums to its LGA row, and a nameless row is kept and
 *      labelled "Unrecorded" so the sum is not silently short by the records
 *      that never got a location. That invariant is what makes the screen
 *      usable for anyone cleaning data, which is its whole reason to exist.
 */

/**
 * Read the programme cards as program => [label => value].
 *
 * The cards render value-then-label and carry a nested "code" line, so the
 * loose-text assertions don't prove anything; pulling each card's title and
 * its three stat pairs out of the markup is both precise and stable.
 *
 * @return array<string, array<string, int>>
 */
function programmeCards($response): array
{
    // Each card runs from its opening div to the start of the next card (or the
    // end of the page for the last one). Bounding the block with the stat-row
    // opening tag below would cut off the very stat-value/stat-label pairs we
    // want to read, because they sit INSIDE that row.
    preg_match_all(
        '/<div class="card programme-card">(.*?)(?=<div class="card programme-card">|$)/s',
        $response->getContent(),
        $blocks,
        PREG_SET_ORDER,
    );

    $cards = [];

    foreach ($blocks as $block) {
        preg_match('/<h2 class="card-title">(.*?)<\/h2>/s', $block[1], $title);
        preg_match_all(
            '/stat-value">\s*([\d,]+)\s*<\/div>\s*<div class="stat-label">\s*([^<]+?)\s*<\/div>/',
            $block[1],
            $stats,
            PREG_SET_ORDER,
        );

        $cards[trim(strip_tags($title[1]))] = collect($stats)
            ->mapWithKeys(fn ($m) => [$m[2] => (int) str_replace(',', '', $m[1])])
            ->all();
    }

    return $cards;
}

/**
 * Read the detail page's breakdown table rows as name => total.
 *
 * @return array<string, int>
 */
function breakdownRows($response): array
{
    preg_match_all(
        '/<td\b[^>]*>\s*(?:<span class="text-muted">)?([^<\n]+?)(?:<\/span>)?\s*<\/td>\s*<td\b[^>]*>\s*([\d,]+)\s*<\/td>\s*<td\b[^>]*>(.*?)<\/td>/s',
        $response->getContent(),
        $rows,
        PREG_SET_ORDER,
    );

    return collect($rows)->mapWithKeys(fn ($row) => [
        trim($row[1]) => (int) str_replace(',', '', $row[2]),
    ])->all();
}

function freezeSummaryClock(): void
{
    test()->travelTo(now()->startOfMonth()->addDays(14)->setTime(10, 0));
}

function summaryLocationChain(): array
{
    $lgaA = Lga::factory()->create(['name' => 'Auyo']);
    $wardA1 = Ward::factory()->forLga($lgaA)->create(['name' => 'Auyakayi']);
    $wardA2 = Ward::factory()->forLga($lgaA)->create(['name' => 'Kafin']);
    $lgaB = Lga::factory()->create(['name' => 'Guri']);

    return compact('lgaA', 'lgaB', 'wardA1', 'wardA2');
}

/* ----------------------------------------------------------------------- */
/* Index: the programme cards                                              */
/* ----------------------------------------------------------------------- */

test('a Program User sees one summary card for their own program, with month and today windows', function () {
    $user = actingAsProgramUser();

    freezeSummaryClock();

    Enrolee::factory()->forUser($user)->count(4)->create();
    Enrolee::factory()->forUser($user)->count(2)->create(['created_at' => now()->startOfMonth()->addDays(2)]);

    $response = $this->get(route('programme-summary.index'));

    $response->assertOk();
    expect(programmeCards($response))->toBe([
        'CEMOG' => ['Total' => 6, 'This month' => 6, 'Today' => 4],
    ]);
});

test('the cards agree with the exact query the enrolee list would run', function () {
    $user = actingAsProgramUser();

    Enrolee::factory()->forUser($user)->count(4)->create();
    Enrolee::factory()->forUser($user)->draft()->count(2)->create();

    // Drafts count: a draft is an enrolment, and the enrolee list counts it.
    $listTotal = (new \App\Services\EnroleeFilters())->queryFor($user)->count();

    expect($listTotal)->toBe(6)
        ->and(programmeCards($this->get(route('programme-summary.index')))['CEMOG']['Total'])->toBe($listTotal);
});

test('a Program User card excludes a colleague in the same program and records from another program', function () {
    $program = test()->program('CEMOG');
    $user = actingAsProgramUser($program);

    Enrolee::factory()->forUser($user)->count(2)->create();

    $colleague = User::factory()->programUser($program)->create();
    Enrolee::factory()->forUser($colleague)->count(3)->create();

    Enrolee::factory()->forUser(User::factory()->programUser(test()->program('VESTILAR'))->create())->count(4)->create();

    $response = $this->get(route('programme-summary.index'));

    expect(programmeCards($response))->toBe([
        'CEMOG' => ['Total' => 2, 'This month' => 2, 'Today' => 2],
    ]);
});

test('a Reviewer sees every record in their program, but never another program', function () {
    $program = test()->program('CEMOG');
    actingAsReviewer($program);

    $creator = User::factory()->programUser($program)->create();
    Enrolee::factory()->forUser($creator)->count(3)->create();

    Enrolee::factory()->forUser(User::factory()->programUser(test()->program('VESTILAR'))->create())->count(5)->create();

    $response = $this->get(route('programme-summary.index'));

    expect(programmeCards($response))->toBe([
        'CEMOG' => ['Total' => 3, 'This month' => 3, 'Today' => 3],
    ]);
});

test('a crafted program filter cannot widen a Program User summary', function () {
    $program = test()->program('CEMOG');
    $acting = actingAsProgramUser($program);

    Enrolee::factory()->forUser($acting)->count(2)->create();

    $other = test()->program('PLHIV');

    // The admin-only program select is not even rendered for this role.
    $response = $this->get(route('programme-summary.index', ['program_id' => $other->id]));

    expect(programmeCards($response))->toBe([
        'CEMOG' => ['Total' => 2, 'This month' => 2, 'Today' => 2],
    ])->and($response)->assertDontSee('name="program_id"', escape: false);
});

test('a crafted status filter cannot shrink the summary totals', function () {
    $user = actingAsProgramUser();

    Enrolee::factory()->forUser($user)->draft()->count(2)->create();
    Enrolee::factory()->forUser($user)->submitted()->count(3)->create();

    // No status control exists on this screen, so a stray query parameter must
    // not be able to turn a total into a drafts-only count.
    expect(programmeCards($this->get(route('programme-summary.index', ['status' => 'draft'])))['CEMOG']['Total'])->toBe(5);
});

test('an Admin sees every program, including one that has never enrolled anyone', function () {
    $admin = actingAsAdmin();

    $cemog = test()->program('CEMOG');
    $vestilar = test()->program('VESTILAR');
    $plhiv = test()->program('PLHIV');

    // Pin created_by to the acting admin so the factory's default creator
    // (a fresh program-user, each with its own random program) cannot leak
    // incidental programs onto this page.
    Enrolee::factory()->forProgram($cemog)->count(3)->create(['created_by' => $admin->id]);
    Enrolee::factory()->forProgram($vestilar)->count(4)->create(['created_by' => $admin->id]);

    // Zero for PLHIV is information, not an absence: the card is still there.
    expect(programmeCards($this->get(route('programme-summary.index'))))->toBe([
        'CEMOG' => ['Total' => 3, 'This month' => 3, 'Today' => 3],
        'PLHIV' => ['Total' => 0, 'This month' => 0, 'Today' => 0],
        'VESTILAR' => ['Total' => 4, 'This month' => 4, 'Today' => 4],
    ]);
});

test('the admin program filter narrows the card list and the totals to that one program', function () {
    actingAsAdmin();

    $cemog = test()->program('CEMOG');
    $vestilar = test()->program('VESTILAR');

    Enrolee::factory()->forProgram($cemog)->count(3)->create();
    Enrolee::factory()->forProgram($vestilar)->count(4)->create();

    $response = $this->get(route('programme-summary.index', ['program_id' => $cemog->id]));

    expect(programmeCards($response))->toBe([
        'CEMOG' => ['Total' => 3, 'This month' => 3, 'Today' => 3],
    ]);
});

test('a location filter narrows the card totals without removing a program', function () {
    $admin = actingAsAdmin();

    ['lgaA' => $lgaA, 'lgaB' => $lgaB] = summaryLocationChain();

    $cemog = test()->program('CEMOG');

    Enrolee::factory()->forProgram($cemog)->count(3)->create(['created_by' => $admin->id, 'lga_id' => $lgaA->id]);
    Enrolee::factory()->forProgram($cemog)->count(2)->create(['created_by' => $admin->id, 'lga_id' => $lgaB->id]);

    $response = $this->get(route('programme-summary.index', ['lga_id' => $lgaA->id]));

    expect(programmeCards($response))->toBe([
        'CEMOG' => ['Total' => 3, 'This month' => 3, 'Today' => 3],
    ]);

    // The page names what the totals cover now that location is in play.
    $response->assertSee('Totals are all-time within the selected location filters.');
});

/* ----------------------------------------------------------------------- */
/* Detail: the headline and the drill-down                                 */
/* ----------------------------------------------------------------------- */

test('the detail page headline matches the LGA breakdown, which keeps an unrecorded row', function () {
    actingAsAdmin();

    ['lgaA' => $lgaA, 'lgaB' => $lgaB, 'wardA1' => $wardA1, 'wardA2' => $wardA2] = summaryLocationChain();

    $cemog = test()->program('CEMOG');

    Enrolee::factory()->forProgram($cemog)->count(2)->create([
        'lga_id' => $lgaA->id, 'ward_id' => $wardA1->id,
    ]);
    Enrolee::factory()->forProgram($cemog)->count(3)->create([
        'lga_id' => $lgaA->id, 'ward_id' => $wardA2->id,
    ]);
    Enrolee::factory()->forProgram($cemog)->create(['lga_id' => $lgaB->id]);
    Enrolee::factory()->forProgram($cemog)->create(['lga_id' => null, 'ward_id' => null]);

    $program = $cemog;
    $response = $this->get(route('programme-summary.show', $program));

    $response->assertOk();

    // Headline: enrolees in view, all-time, every status — 2 + 3 + 1 + 1.
    $response->assertSee('7');

    // The LGA rows, and they add back up to the headline even with a row that
    // has no location recorded in it.
    expect(breakdownRows($response))->toBe([
        'Auyo' => 5,
        'Guri' => 1,
        'Unrecorded' => 1,
    ]);

    $response->assertSee('Breakdown by LGA')
        ->assertSee('No location recorded');
});

test('the LGA rows drill down to a ward table of the same LGA', function () {
    actingAsAdmin();

    ['lgaA' => $lgaA, 'lgaB' => $lgaB, 'wardA1' => $wardA1, 'wardA2' => $wardA2] = summaryLocationChain();

    $cemog = test()->program('CEMOG');

    Enrolee::factory()->forProgram($cemog)->count(2)->create([
        'lga_id' => $lgaA->id, 'ward_id' => $wardA1->id,
    ]);
    Enrolee::factory()->forProgram($cemog)->count(3)->create([
        'lga_id' => $lgaA->id, 'ward_id' => $wardA2->id,
    ]);
    Enrolee::factory()->forProgram($cemog)->create(['lga_id' => $lgaB->id]);

    $program = $cemog;

    // The LGA row is a link into the same programme, that LGA's page.
    $listing = $this->get(route('programme-summary.show', $program));
    $listing->assertSee(
        route('programme-summary.show', ['program' => $program->id, 'lga_id' => $lgaA->id]),
    );

    $response = $this->get(route('programme-summary.show', [
        'program' => $program->id, 'lga_id' => $lgaA->id,
    ]));

    $response->assertOk()
        ->assertSee('Breakdown by ward');

    // The two Auyo wards, and no Guri row: the ward table is of the LGA.
    expect(breakdownRows($response))->toBe([
        'Auyakayi' => 2,
        'Kafin' => 3,
    ]);

    // Each ward row links into the enrolee list, filtered to exactly that ward.
    $response->assertSee(
        route('enrolees.index', ['program_id' => $program->id, 'lga_id' => $lgaA->id, 'ward_id' => $wardA2->id]),
    );
});

test('choosing a ward shows the ward table for its LGA without the ward filter pinching the rows', function () {
    actingAsAdmin();

    ['lgaA' => $lgaA, 'lgaB' => $lgaB, 'wardA1' => $wardA1, 'wardA2' => $wardA2] = summaryLocationChain();

    $cemog = test()->program('CEMOG');

    Enrolee::factory()->forProgram($cemog)->count(2)->create([
        'lga_id' => $lgaA->id, 'ward_id' => $wardA1->id,
    ]);
    Enrolee::factory()->forProgram($cemog)->count(3)->create([
        'lga_id' => $lgaA->id, 'ward_id' => $wardA2->id,
    ]);
    Enrolee::factory()->forProgram($cemog)->create(['lga_id' => $lgaB->id]);

    $program = $cemog;

    $response = $this->get(route('programme-summary.show', [
        'program' => $program->id,
        'lga_id' => $lgaA->id,
        'ward_id' => $wardA2->id,
    ]));

    $response->assertOk();

    // Headline honours the chosen ward (only Kafin's three).
    $response->assertSee('Breakdown by ward')
        ->assertSee($lgaA->name)
        ->assertSee($wardA2->name);

    // But the table is the LGA's breakdown: both wards, so the drill-down can
    // show where the LGA's other records live. This is the data-cleaning
    // promise of the screen.
    expect(breakdownRows($response))->toBe([
        'Auyakayi' => 2,
        'Kafin' => 3,
    ]);
});

test('a date filter narrows the detail page and is carried by the links', function () {
    actingAsAdmin();

    ['lgaA' => $lgaA] = summaryLocationChain();

    $cemog = test()->program('CEMOG');

    Enrolee::factory()->forProgram($cemog)->count(2)->create([
        'lga_id' => $lgaA->id,
        'created_at' => now()->startOfMonth(),
    ]);
    Enrolee::factory()->forProgram($cemog)->create([
        'lga_id' => $lgaA->id,
        'created_at' => now()->subMonths(4),
    ]);

    $program = $cemog;
    $from = now()->startOfMonth()->toDateString();

    $response = $this->get(route('programme-summary.show', [
        'program' => $program->id, 'from' => $from,
    ]));

    $response->assertOk();

    // The last LGA row: only the two this-month records survived the range.
    expect(breakdownRows($response)['Auyo'])->toBe(2);

    // And the page says the totals are range-limited rather than pretending
    // to be all-time.
    $response->assertSee("Totals cover the selected date range; 'This month' and 'Today' count enrolments inside that range on their calendar windows.");

    // Browse link carries the range through to the enrolee list.
    $response->assertSee(
        route('enrolees.index', ['program_id' => $program->id, 'from' => $from]),
    );
});

/* ----------------------------------------------------------------------- */
/* Authorisation                                                           */
/* ----------------------------------------------------------------------- */

test('a Program User may open a summary of their own program', function () {
    $program = test()->program('CEMOG');
    actingAsProgramUser($program);

    Enrolee::factory()->forProgram($program)->create();

    $this->get(route('programme-summary.show', $program))
        ->assertOk()
        ->assertSee('Breakdown by LGA');
});

test('a Program User is refused a summary of any other program', function () {
    actingAsProgramUser(test()->program('CEMOG'));

    $this->get(route('programme-summary.show', test()->program('PLHIV')))
        ->assertForbidden();
});

test('a Reviewer is refused a summary outside their program', function () {
    actingAsReviewer(test()->program('CEMOG'));

    $this->get(route('programme-summary.show', test()->program('VESTILAR')))
        ->assertForbidden();
});

test('an Admin may open a summary of every program', function () {
    actingAsAdmin();

    Enrolee::factory()->forProgram(test()->program('VESTILAR'))->count(2)->create();

    $this->get(route('programme-summary.show', test()->program('VESTILAR')))
        ->assertOk()
        ->assertSee('Breakdown by LGA');
});

test('the Programme Summary nav is present for every role', function () {
    $program = test()->program('CEMOG');

    foreach ([actingAsAdmin(), actingAsProgramUser($program), actingAsReviewer($program)] as $user) {
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('programme-summary.index'))
            ->assertSee('Programme Summary');
    }
});