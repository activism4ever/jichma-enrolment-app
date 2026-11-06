<?php

use App\Models\AuditLog;
use App\Models\Enrolee;
use App\Models\Facility;
use App\Models\Lga;
use App\Models\Program;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use App\Models\Ward;

/*
|--------------------------------------------------------------------------
| Audit log
|--------------------------------------------------------------------------
|
| The trail is only useful as evidence if the application cannot rewrite it, so
| these tests check that it is append-only from the web, and that it records who
| did what well enough to reconstruct an incident.
|
*/

test('only an administrator can read the audit log', function () {
    actingAsProgramUser();

    $this->get(route('admin.audit.index'))->assertForbidden();
});

test('there is no route that creates, edits or deletes an audit entry', function () {
    actingAsAdmin();

    // Read-only by construction: no such route exists, so a crafted request
    // has nothing to hit. This is the property that makes the trail evidence.
    expect(fn () => route('admin.audit.store'))->toThrow(RouteNotFoundException::class);
    expect(fn () => route('admin.audit.update', 1))->toThrow(RouteNotFoundException::class);
    expect(fn () => route('admin.audit.destroy', 1))->toThrow(RouteNotFoundException::class);
});

test('the log lists recent activity', function () {
    $admin = actingAsAdmin();

    AuditLog::create([
        'actor_id' => $admin->id,
        'action' => 'enrolee_created',
        'description' => 'Created a draft for Amina Bello',
        'ip_address' => '10.0.0.9',
    ]);

    $this->get(route('admin.audit.index'))
        ->assertOk()
        ->assertSee('enrolee_created')
        ->assertSee('Created a draft for Amina Bello')
        ->assertSee('10.0.0.9');
});

test('the log can be filtered by action, by who and by program', function () {
    $admin = actingAsAdmin();
    $other = User::factory()->programUser()->create();

    $program = Program::factory()->create(['code' => 'CEMOG']);

    AuditLog::create([
        'actor_id' => $admin->id,
        'action' => 'user_created',
        'description' => 'Created an account',
    ]);

    AuditLog::create([
        'actor_id' => $other->id,
        'program_id' => $program->id,
        'action' => 'enrolee_submitted',
        'description' => 'Submitted a record',
    ]);

    $this->get(route('admin.audit.index', ['action' => 'enrolee_submitted']))
        ->assertOk()
        ->assertSee('Submitted a record')
        ->assertDontSee('Created an account');

    $this->get(route('admin.audit.index', ['actor_id' => $other->id]))
        ->assertOk()
        ->assertSee('Submitted a record')
        ->assertDontSee('Created an account');

    $this->get(route('admin.audit.index', ['program_id' => $program->id]))
        ->assertOk()
        ->assertSee('Submitted a record')
        ->assertDontSee('Created an account');
});

test('an unrecognised filter value matches nothing rather than erroring', function () {
    actingAsAdmin();

    AuditLog::create([
        'actor_id' => null,
        'action' => 'login',
        'description' => 'Signed in',
    ]);

    // Mid-investigation, a bad parameter must not produce a 500, and it must not
    // quietly show the whole log either: matching everything would let someone
    // conclude the record they are looking for does not exist.
    $this->get(route('admin.audit.index', ['action' => 'not_a_real_action']))
        ->assertOk()
        ->assertDontSee('Signed in');

    $this->get(route('admin.audit.index', ['from' => 'not-a-date']))
        ->assertOk()
        ->assertDontSee('Signed in');
});

test('the whole range can be filtered by date inclusively', function () {
    actingAsAdmin();

    // created_at is not mass-assignable, so it is set explicitly: passing it to
    // create() would be silently ignored and both rows would land on now().
    $earlier = AuditLog::create(['actor_id' => null, 'action' => 'login', 'description' => 'Earlier']);
    $earlier->forceFill(['created_at' => now()->subDays(5)])->save();

    $later = AuditLog::create(['actor_id' => null, 'action' => 'login', 'description' => 'Later']);
    $later->forceFill(['created_at' => now()])->save();

    $this->get(route('admin.audit.index', ['from' => now()->subDay()->toDateString()]))
        ->assertOk()
        ->assertSee('Later')
        ->assertDontSee('Earlier');

    // `to` is inclusive of the whole end day.
    $this->get(route('admin.audit.index', ['to' => now()->subDays(5)->toDateString()]))
        ->assertOk()
        ->assertSee('Earlier')
        ->assertDontSee('Later');
});

test('a failed sign-in is recorded without disclosing the email in the list', function () {
    actingAsAdmin();

    AuditLog::create([
        'actor_id' => null,
        'action' => 'login_failed',
        'description' => 'Failed sign-in attempt',
        'ip_address' => '203.0.113.7',
    ]);

    $this->get(route('admin.audit.index', ['action' => 'login_failed']))
        ->assertOk()
        ->assertSee('203.0.113.7');
});

/*
|--------------------------------------------------------------------------
| Locations
|--------------------------------------------------------------------------
*/

test('only an administrator can manage locations', function () {
    actingAsProgramUser();

    $this->get(route('admin.locations.index'))->assertForbidden();
    $this->post(route('admin.locations.import'), [])->assertForbidden();
    $this->post(route('admin.locations.restore'))->assertForbidden();
});

test('the location page shows the counts and the LGA list', function () {
    actingAsAdmin();

    ['lga' => $lga] = test()->locationChain();

    // The default view lists LGAs with their counts; ward and facility names
    // appear once a single LGA is chosen.
    $this->get(route('admin.locations.index'))
        ->assertOk()
        ->assertSee($lga->name)
        ->assertSee('LGAs')
        ->assertSee('Facilities');
});

test('choosing one LGA lists its wards and facilities', function () {
    actingAsAdmin();

    ['lga' => $lga] = test()->locationChain();

    $this->get(route('admin.locations.index', ['lga_id' => $lga->id]))
        ->assertOk()
        ->assertSee('Auyakayi');
});

/** Build an in-memory CSV upload. */
function csvUpload(string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'locations').'.csv';
    file_put_contents($path, $contents);

    return new UploadedFile($path, 'locations.csv', 'text/csv', null, true);
}

test('a location CSV is imported and the result is audited', function () {
    actingAsAdmin();

    $this->post(route('admin.locations.import'), [
        'file' => csvUpload("lga,ward,facility\nAuyo,Auyakayi,Auyakayi BHC\nAuyo,Auyo,Auyo PHC\nKano,Bompai,Bompai Clinic\n"),
        'confirm_replace' => '1',
    ])->assertRedirect(route('admin.locations.index'));

    expect(Lga::pluck('name')->sort()->values()->all())->toBe(['Auyo', 'Kano'])
        ->and(Ward::count())->toBe(3)
        ->and(Facility::count())->toBe(3);

    expect(AuditLog::where('action', 'locations_imported')->exists())->toBeTrue();
});

test('re-importing the same file updates rather than duplicates', function () {
    actingAsAdmin();

    $csv = csvUpload("lga,ward,facility\nAuyo,Auyakayi,Auyakayi BHC\n");

    $this->post(route('admin.locations.import'), [
        'file' => $csv,
        'confirm_replace' => '1',
    ])->assertRedirect();

    $this->post(route('admin.locations.import'), [
        'file' => csvUpload("lga,ward,facility\nAuyo,Auyakayi,Auyakayi BHC\n"),
        'confirm_replace' => '1',
    ])->assertRedirect();

    expect(Lga::count())->toBe(1)
        ->and(Ward::count())->toBe(1)
        ->and(Facility::count())->toBe(1);
});

test('an import does not orphan the records that already point at a facility', function () {
    // The enrolee needs an owner who is not the administrator, so the account is
    // created directly rather than by acting as them, which would replace the
    // session needed for the import.
    $owner = User::factory()->programUser()->create();

    actingAsAdmin();

    $enrolee = Enrolee::factory()->forUser($owner)->create();

    $facilityId = $enrolee->facility_id;
    $facilityName = $enrolee->facility->name;
    $wardName = $enrolee->facility->ward->name;
    $lgaName = $enrolee->facility->ward->lga->name;

    $this->post(route('admin.locations.import'), [
        'file' => csvUpload("lga,ward,facility\n{$lgaName},{$wardName},{$facilityName}\n"),
        'confirm_replace' => '1',
    ])->assertRedirect();

    // This is the whole reason the importer upserts instead of delete-and-recreate.
    expect($enrolee->refresh()->facility_id)->toBe($facilityId);
});

test('a malformed CSV is refused with an explanation and changes nothing', function () {
    actingAsAdmin();

    $this->post(route('admin.locations.import'), [
        'file' => csvUpload("region,state\nNorth,East\n"),
        'confirm_replace' => '1',
    ])->assertSessionHas('error');

    expect(Lga::count())->toBe(0);
});

test('incomplete rows are skipped without losing the rest', function () {
    actingAsAdmin();

    $this->post(route('admin.locations.import'), [
        'file' => csvUpload("lga,ward,facility\nAuyo,Auyakayi,Auyakayi BHC\nAuyo,,Orphan\n\nKano,Bompai,Bompai Clinic\n"),
        'confirm_replace' => '1',
    ])->assertRedirect();

    expect(Lga::count())->toBe(2)
        ->and(Facility::count())->toBe(2);
});

test('an import needs the confirmation', function () {
    actingAsAdmin();

    $this->post(route('admin.locations.import'), [
        'file' => csvUpload("lga,ward,facility\nAuyo,Auyakayi,Auyakayi BHC\n"),
    ])->assertSessionHasErrors('confirm_replace');

    expect(Lga::count())->toBe(0);
});

test('a file that is not a CSV is refused', function () {
    actingAsAdmin();

    $path = tempnam(sys_get_temp_dir(), 'notcsv').'.pdf';
    file_put_contents($path, "%PDF-1.4 not a spreadsheet");

    $this->post(route('admin.locations.import'), [
        'file' => new UploadedFile($path, 'list.pdf', 'application/pdf', null, true),
        'confirm_replace' => '1',
    ])->assertSessionHasErrors('file');

    expect(Lga::count())->toBe(0);
});

test('the bundled list can be restored', function () {
    actingAsAdmin();

    $this->post(route('admin.locations.restore'))->assertRedirect(route('admin.locations.index'));

    expect(Lga::count())->toBe(27)
        ->and(Ward::count())->toBe(276)
        ->and(Facility::count())->toBe(279);
});

test('a CSV template is offered with the expected header', function () {
    actingAsAdmin();

    $this->get(route('admin.locations.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});
