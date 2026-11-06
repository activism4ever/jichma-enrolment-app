<?php

use App\Jobs\GenerateExport;
use App\Models\AuditLog;
use App\Models\Enrolee;
use App\Models\Export;
use App\Models\Program;
use App\Models\User;
use App\Services\ExportService;
use App\Services\PhotoService;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Sensitive-programme masking
|--------------------------------------------------------------------------
|
| The is_sensitive column decides whether a programme's NIN and phone numbers
| are masked wherever they are listed. These tests hold the boundaries that
| are easy to lose while hunting for regressions: the mask itself appears in
| the list and in search results but not on the detail page, renaming a
| sensitive programme does not drop its protection, a newly created
| programme cannot bypass masking by being young, and the dashboard never
| renders an unmasked identifier. The one deliberate exception, the bulk
| admin export, is pinned too: an export file carries full NINs because it
| is an audited, administrator-only extract, not a screen.
|
*/

const MASKED_NIN = '12345678901';
const MASKED_PHONE = '08031234567';
const PLAIN_NIN = '99999999999';
const PLAIN_PHONE = '08055556666';

/** A masked NIN keeps only its last four digits. */
function sevenStarsFour(string $value): string
{
    return str_repeat('*', strlen($value) - 4).substr($value, -4);
}

/** A sensitive record and a plain one, with identifiers that cannot collide. */
function sensitiveAndPlainRecords(): array
{
    $sensitive = Program::factory()->sensitive()->create(['code' => 'SENS']);
    $plain = Program::factory()->create(['code' => 'PLAIN']);

    $sensitiveEnrolee = Enrolee::factory()->forProgram($sensitive)->create([
        'surname' => 'MaskedUser',
        'nin' => MASKED_NIN,
        'phone' => MASKED_PHONE,
    ]);
    $plainEnrolee = Enrolee::factory()->forProgram($plain)->create([
        'surname' => 'PlainUser',
        'nin' => PLAIN_NIN,
        'phone' => PLAIN_PHONE,
    ]);

    return [$sensitiveEnrolee, $plainEnrolee];
}

test('a list masks NIN and phone for a sensitive programme, and only for it', function () {
    [$sensitiveEnrolee, $plainEnrolee] = sensitiveAndPlainRecords();

    actingAsAdmin();

    $this->get(route('enrolees.index'))
        ->assertOk()
        // The sensitive row keeps only the last four of each identifier.
        ->assertSee(sevenStarsFour(MASKED_NIN))
        ->assertSee(sevenStarsFour(MASKED_PHONE))
        ->assertDontSee(MASKED_NIN)
        ->assertDontSee(MASKED_PHONE)
        // The non-sensitive row is unmasked, and shows the sensitive badge.
        ->assertSee(PLAIN_NIN)
        ->assertSee(PLAIN_PHONE)
        ->assertSee('Sensitive')
        ->assertSee($sensitiveEnrolee->full_name)
        ->assertSee($plainEnrolee->full_name);
});

test('the detail page shows the full NIN and phone of a sensitive record', function () {
    [$sensitiveEnrolee] = sensitiveAndPlainRecords();

    actingAsAdmin();

    $this->get(route('enrolees.show', $sensitiveEnrolee))
        ->assertOk()
        ->assertSee(MASKED_NIN)
        ->assertSee(MASKED_PHONE);
});

test('search results mask NIN and phone for a sensitive programme', function () {
    // Search runs through the same list view, so the mask must survive it.
    [$sensitiveEnrolee] = sensitiveAndPlainRecords();

    actingAsAdmin();

    $this->get(route('enrolees.index', ['search' => 'MaskedUser']))
        ->assertOk()
        ->assertSee(sevenStarsFour(MASKED_NIN))
        ->assertSee(sevenStarsFour(MASKED_PHONE))
        ->assertDontSee(MASKED_NIN)
        ->assertDontSee(MASKED_PHONE);
});

test('a reviewer list masks NIN and phone for a sensitive programme', function () {
    [$sensitiveEnrolee] = sensitiveAndPlainRecords();

    actingAsReviewer(Program::where('code', 'SENS')->sole());

    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee(sevenStarsFour(MASKED_NIN))
        ->assertSee(sevenStarsFour(MASKED_PHONE))
        ->assertDontSee(MASKED_NIN)
        ->assertDontSee(MASKED_PHONE);
});

test('renaming a sensitive programme does not drop its list masking', function () {
    [$sensitiveEnrolee, $plainEnrolee] = sensitiveAndPlainRecords();
    $program = Program::where('code', 'SENS')->sole();

    actingAsAdmin();

    $this->put(route('admin.programs.update', $program), [
        'name' => 'Renamed Sensitive',
        'description' => '',
        'is_sensitive' => '1',
    ])->assertRedirect();

    expect($program->refresh()->name)->toBe('Renamed Sensitive')
        ->and($program->isSensitive())->toBeTrue();

    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee(sevenStarsFour(MASKED_NIN))
        ->assertDontSee(MASKED_NIN);
});

test('a newly created sensitive programme is masked from its first list', function () {
    actingAsAdmin();

    // Add a brand-new sensitive programme through the administration screens...
    $this->post(route('admin.programs.store'), [
        'code' => 'NEWSENS',
        'name' => 'New Sensitive',
        'description' => '',
        'is_sensitive' => '1',
    ])->assertRedirect();

    // ...and register into it. The masking follows the column, not the code.
    $program = Program::where('code', 'NEWSENS')->sole();

    Enrolee::factory()->forProgram($program)->create([
        'surname' => 'NewlySensitive',
        'nin' => MASKED_NIN,
        'phone' => MASKED_PHONE,
    ]);

    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee(sevenStarsFour(MASKED_NIN))
        ->assertDontSee(MASKED_NIN);
});

test('the dashboard never renders an unmasked identifier', function () {
    [$sensitiveEnrolee] = sensitiveAndPlainRecords();

    actingAsAdmin();

    // The dashboard lists names and programmes only, never NINs or phones.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee($sensitiveEnrolee->full_name)
        ->assertDontSee(MASKED_NIN)
        ->assertDontSee(MASKED_PHONE);
});

test('an export file carries the full NIN and phone of a sensitive programme', function () {
    // The one deliberate exception to list masking: an export is an audited,
    // administrator-only bulk extract, so the file holds complete identifiers.
    Storage::fake('private');

    $admin = actingAsAdmin();
    $program = Program::factory()->sensitive()->create(['code' => 'SENS']);

    Enrolee::factory()->forProgram($program)->submitted()->create([
        'surname' => 'ExportedSensitive',
        'nin' => MASKED_NIN,
        'phone' => MASKED_PHONE,
    ]);

    $export = Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'pending',
        'disk' => 'private',
        'filters' => ['program_id' => $program->id],
    ]);

    (new GenerateExport($export->id, ['program_id' => $program->id]))->handle(
        app(ExportService::class),
        app(PhotoService::class),
    );

    $export->refresh();

    expect($export->status)->toBe('ready')
        ->and($export->row_count)->toBe(1);

    $csv = Storage::disk('private')->get($export->path);

    expect($csv)->toContain(MASKED_NIN)
        ->and($csv)->toContain(MASKED_PHONE)
        ->and($csv)->not->toContain(sevenStarsFour(MASKED_NIN));

    // The full read is on the trail, named against the requesting admin.
    expect(AuditLog::where('action', 'export_completed')->exists())->toBeTrue();
});