<?php

use App\Jobs\GenerateExport;
use App\Models\AuditLog;
use App\Models\Enrolee;
use App\Models\Export;
use App\Models\Lga;
use App\Models\Program;
use App\Models\User;
use App\Services\ExportService;
use App\Services\PhotoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** Run the queued export inline; queueing itself is asserted separately. */
function runExportJob(Export $export, array $filters = []): Export
{
    (new GenerateExport($export->id, $filters))->handle(
        app(ExportService::class),
        app(PhotoService::class),
    );

    return $export->refresh();
}

/*
|--------------------------------------------------------------------------
| Exports
|--------------------------------------------------------------------------
|
| Exports carry the most sensitive data the application produces, so the tests
| concentrate on three things: that the file is written outside the web root,
| that only the administrator who asked for it can download it, and that the
| filters are re-applied when the job runs rather than trusted from the request.
|
*/

beforeEach(function () {
    // Everything goes to the private disk. faking it keeps generated files out
    // of the real storage tree and lets the tests assert on the paths.
    Storage::fake('private');
});

/** Build an export row in a given state without going through the form. */
function fakeExport(User $admin, array $attributes = []): Export
{
    return Export::create(array_merge([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'ready',
        'disk' => 'private',
        'path' => 'exports/already-there.csv',
        'expires_at' => now()->addDay(),
    ], $attributes));
}

test('only an administrator can request an export', function () {
    Queue::fake();

    actingAsProgramUser();

    $this->post(route('admin.exports.store'), ['kind' => 'csv'])->assertForbidden();

    expect(Export::count())->toBe(0);
});

test('requesting an export records the request and queues the work', function () {
    Queue::fake();

    $admin = actingAsAdmin();

    $this->post(route('admin.exports.store'), [
        'kind' => 'csv',
        'status' => 'submitted',
    ])->assertRedirect(route('admin.exports.index'));

    $export = Export::sole();

    expect($export->requested_by)->toBe($admin->id)
        ->and($export->status)->toBe('pending')
        ->and($export->filters)->toMatchArray(['status' => 'submitted']);

    // The job must run off the request; a synchronous export would hold a web
    // request open far longer than the host allows.
    Queue::assertPushed(GenerateExport::class, fn (GenerateExport $job) => $job->exportId === $export->id);

    expect(AuditLog::where('action', 'export_requested')->exists())->toBeTrue();
});

test('an end date before the start date is refused', function () {
    Queue::fake();

    actingAsAdmin();

    $this->post(route('admin.exports.store'), [
        'kind' => 'csv',
        'from' => '2026-05-01',
        'to' => '2026-01-01',
    ])->assertSessionHasErrors('to');

    expect(Export::count())->toBe(0);
});

test('a reversed range is explained in the words of the fields on screen', function () {
    Queue::fake();

    actingAsAdmin();

    $response = $this->post(route('admin.exports.store'), [
        'kind' => 'csv',
        'from' => '2026-05-01',
        'to' => '2026-01-01',
    ]);

    // The two boxes are labelled Registered from / Registered to, so the message
    // has to use those names rather than "the to field".
    $response->assertSessionHasErrors([
        'to' => 'Registered to cannot be earlier than Registered from.',
    ]);

    // The same wording where the administrator actually reads it: the form is
    // redisplayed with the error bag intact.
    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertDontSee('to field')
        ->assertSee('Registered to cannot be earlier than Registered from.');

    expect(Export::count())->toBe(0);
});

test('a range that reads forwards is accepted and stored', function () {
    Queue::fake();

    actingAsAdmin();

    $this->post(route('admin.exports.store'), [
        'kind' => 'csv',
        'from' => '2026-10-01',
        'to' => '2026-10-02',
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.exports.index'));

    // Both bounds survive into the queued job, because that is what the export
    // is actually filtered by.
    expect(Export::sole()->filters)->toMatchArray([
        'from' => '2026-10-01',
        'to' => '2026-10-02',
    ]);
});

test('a single day range is accepted', function () {
    Queue::fake();

    actingAsAdmin();

    // from and to the same date is the "today" case, and must not be treated
    // as an empty or reversed range.
    $this->post(route('admin.exports.store'), [
        'kind' => 'csv',
        'from' => '2026-10-01',
        'to' => '2026-10-01',
    ])->assertSessionHasNoErrors();

    expect(Export::sole()->filters)->toMatchArray([
        'from' => '2026-10-01',
        'to' => '2026-10-01',
    ]);
});

test('a date that is not in YYYY-MM-DD is refused with a usable message', function () {
    Queue::fake();

    actingAsAdmin();

    $this->post(route('admin.exports.store'), [
        'kind' => 'csv',
        'from' => '01/10/2026',
        'to' => '2026-10-02',
    ])->assertSessionHasErrors([
        'from' => 'Registered from must be a real date in YYYY-MM-DD format.',
    ]);

    // A value the database could not compare against a timestamp is dropped
    // rather than turned into a filter that matches everything.
    expect(Export::count())->toBe(0);
});

test('an unknown format is refused', function () {
    Queue::fake();

    actingAsAdmin();

    $this->post(route('admin.exports.store'), ['kind' => 'pdf'])
        ->assertSessionHasErrors('kind');
});

test('the job writes a CSV to the private disk and marks the export ready', function () {
    $admin = actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG']);
    Enrolee::factory()->forProgram($program)->submitted()->count(3)->create();

    $export = fakeExport($admin, ['kind' => 'csv', 'status' => 'pending']);

    $export = runExportJob($export, ['program_id' => $program->id]);

    expect($export->status)->toBe('ready')
        ->and($export->row_count)->toBe(3)
        ->and($export->path)->not->toBeNull()
        ->and($export->size_bytes)->toBeGreaterThan(0)
        ->and($export->expires_at->isFuture())->toBeTrue();

    Storage::disk('private')->assertExists($export->path);

    // The header row plus one row per enrolee.
    $csv = Storage::disk('private')->get($export->path);

    expect(substr_count($csv, "\n"))->toBeGreaterThanOrEqual(4);
});

test('a completed export is written to the audit trail against the requester', function () {
    // Deliberately signed in as nobody. A queue worker has no session, and
    // the entry still has to name the administrator the export was built
    // for — an unattributed completion is only marginally better than none.
    $admin = User::factory()->admin()->create();

    Enrolee::factory()->submitted()->count(2)->create();

    $export = fakeExport($admin, [
        'status' => 'pending',
        'filters' => ['status' => 'submitted'],
    ]);

    $export = runExportJob($export, ['status' => 'submitted']);

    $entry = AuditLog::where('action', 'export_completed')->sole();

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($export->id)
        // The count the job produced, not the one the screen believed when
        // the request was made.
        ->and($entry->new_values['record_count'])->toBe(2)
        ->and($entry->new_values['kind'])->toBe('csv')
        ->and($entry->new_values['requested_by'])->toBe($admin->id)
        ->and($entry->description)->toContain('Completed a CSV export of 2 enrolees');
});

test('the generated file is never web-accessible', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin, ['status' => 'pending']);

    $path = runExportJob($export)->path;

    expect($path)->toStartWith('exports/');

    // The private disk root is outside public/, and nothing maps it to a URL.
    expect(Storage::disk('private')->path($path))->not->toContain(public_path());
});

test('the job only exports rows the requester can still see', function () {
    $admin = actingAsAdmin();

    $mine = Program::factory()->create(['code' => 'CEMOG']);
    $theirs = Program::factory()->create(['code' => 'OTHER']);

    $requester = User::factory()->programUser($mine)->create();

    // Two records the requester created inside their program.
    Enrolee::factory()->forUser($requester)->forProgram($mine)->count(2)->create();
    // And five they never had any business seeing.
    Enrolee::factory()->forUser($requester)->forProgram($theirs)->count(5)->create();

    // The role and program are re-read at run time, so a request made while
    // broader access was in force cannot leak rows the requester can no longer
    // see by the time the job actually runs.
    $export = fakeExport($admin, ['status' => 'pending', 'requested_by' => $requester->id]);

    expect(runExportJob($export)->row_count)->toBe(2);
});

test('filters are applied by the job, not by the request', function () {
    $admin = actingAsAdmin();

    Enrolee::factory()->submitted()->count(4)->create();
    Enrolee::factory()->count(6)->create();

    $export = fakeExport($admin, ['status' => 'pending']);

    expect(runExportJob($export, ['status' => 'submitted'])->row_count)->toBe(4);
});

test('a failing export records the reason instead of hanging', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin, ['kind' => 'photos', 'status' => 'pending']);

    // A photo bundle matching nothing refuses rather than producing an empty
    // archive. handle() rethrows so the queue records the failure too, so the
    // exception is expected here; what matters is that the row explains itself
    // rather than sitting at "processing" forever.
    expect(fn () => runExportJob($export))
        ->toThrow(RuntimeException::class, 'No enrolees matched');

    $export->refresh();

    expect($export->status)->toBe('failed')
        ->and($export->error)->toContain('No enrolees matched')
        ->and($export->path)->toBeNull();
});

test('an export that fails part-way leaves no partial file behind', function () {
    $admin = actingAsAdmin();

    Enrolee::factory()->count(4)->create();

    $export = fakeExport($admin, ['status' => 'pending']);

    // Fails *after* the staging file has been created, which is the case the two
    // refusals above cannot reach: both of those throw before a byte is written.
    // A partial file that exports:prune cannot see — a failed export has no path
    // recorded — would stay on the disk for good.
    $this->instance(ExportService::class, new class extends ExportService
    {
        public function toCsv(\Illuminate\Database\Eloquent\Builder $query, string $absolutePath, ?callable $progress = null): array
        {
            file_put_contents($absolutePath, "id,surname\n1,HalfWritten\n");

            throw new RuntimeException('The disk went away mid-write.');
        }
    });

    expect(fn () => runExportJob($export))->toThrow(RuntimeException::class, 'mid-write');

    $temporary = 'exports/tmp/'.$export->uuid.'.part';

    expect(Storage::disk('private')->exists($temporary))->toBeFalse()
        ->and(Storage::disk('private')->files('exports'))->toBeEmpty()
        ->and($export->refresh()->status)->toBe('failed')
        ->and($export->path)->toBeNull();
});

test('a photo bundle above the row limit is refused before any work', function () {
    $admin = actingAsAdmin();

    config(['exports.max_photo_bundle_rows' => 2]);

    Enrolee::factory()->count(3)->create(['photo_path' => null]);

    $export = fakeExport($admin, ['kind' => 'photos', 'status' => 'pending']);

    expect(fn () => runExportJob($export))
        ->toThrow(RuntimeException::class, 'above the 2');

    expect($export->refresh()->status)->toBe('failed');
});

test('the requesting administrator can download the file', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin);

    Storage::disk('private')->put($export->path, "surname,nin\nBello,12345678901\n");

    $this->get(route('admin.exports.download', $export))
        ->assertOk();

    // Laravel appends the charset, so match the type rather than the whole
    // header value.
    expect($this->get(route('admin.exports.download', $export))->headers->get('Content-Type'))
        ->toContain('text/csv');

    expect(AuditLog::where('action', 'export_downloaded')->exists())->toBeTrue();
});

test('another administrator cannot download somebody elses export', function () {
    $owner = User::factory()->admin()->create();
    $export = fakeExport($owner);

    Storage::disk('private')->put($export->path, "surname,nin\nBello,12345678901\n");

    actingAsAdmin();

    // Same role, different person: authorisation is per-request, not per-role.
    $this->get(route('admin.exports.download', $export))->assertForbidden();
});

test('an expired export cannot be downloaded', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin, ['expires_at' => now()->subMinute()]);

    Storage::disk('private')->put($export->path, "surname\nBello\n");

    $this->get(route('admin.exports.download', $export))->assertRedirect();

    expect(AuditLog::where('action', 'export_download_denied')->exists())->toBeTrue();
});

test('an export that is not ready cannot be downloaded', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin, ['status' => 'processing', 'path' => null]);

    $this->get(route('admin.exports.download', $export))->assertNotFound();
});

test('a missing file on a ready export is reported rather than served', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin);

    // The row says ready, the disk says otherwise: a restored database or a
    // cleared disk. Offer a graceful message, not a 500.
    Storage::disk('private')->assertMissing($export->path);

    $this->get(route('admin.exports.download', $export))->assertRedirect();

    expect($export->refresh()->status)->toBe('failed');
});

test('discarding an export deletes the file as well as the row', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin);

    Storage::disk('private')->put($export->path, "surname\nBello\n");

    $this->delete(route('admin.exports.destroy', $export))->assertRedirect();

    expect(Export::count())->toBe(0);

    // Passport photographs must not outlive the request that produced them.
    Storage::disk('private')->assertMissing('exports/already-there.csv');
});

test('the export index only lists your own requests', function () {
    $someoneElse = User::factory()->admin()->create();
    $someoneElseExport = fakeExport($someoneElse, ['path' => 'exports/theirs.csv', 'kind' => 'zip']);

    $mine = fakeExport(actingAsAdmin(), ['path' => 'exports/mine.csv', 'kind' => 'zip']);

    $this->get(route('admin.exports.index'))
        ->assertOk()
        // Assert on the download link, which is the only per-row identifier the
        // page renders: the stored path is deliberately not shown.
        ->assertDontSee(route('admin.exports.download', $someoneElseExport))
        ->assertSee(route('admin.exports.download', $mine));
});

test('a program user cannot reach the export screens', function () {
    actingAsProgramUser();

    $this->get(route('admin.exports.index'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| No password, and an audit trail that stands on its own
|--------------------------------------------------------------------------
|
| The form no longer asks the administrator to retype their password, so these
| tests hold the replacements for what that check was defending. A bulk read of
| NINs and passport photographs is accountable through the audit trail: who
| asked, for what format, with which filters, over how many records, and when —
| and separately, every time the finished file was collected.
|
*/

test('queueing an export asks for no password', function () {
    Queue::fake();

    $admin = actingAsAdmin();

    $this->post(route('admin.exports.store'), ['kind' => 'csv'])
        ->assertRedirect(route('admin.exports.index'))
        ->assertSessionHasNoErrors();

    $export = Export::sole();

    expect($export->requested_by)->toBe($admin->id)
        ->and($export->status)->toBe('pending');

    Queue::assertPushed(GenerateExport::class);
});

test('the export form has no password field', function () {
    actingAsAdmin();

    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertDontSee('name="password"', escape: false)
        ->assertDontSee('Re-enter your password', escape: false);
});

test('a password posted to the export form is ignored, not stored', function () {
    Queue::fake();

    $secret = 'Unmistakable-Secret-9f3';

    actingAsAdmin(['password' => $secret]);

    $this->post(route('admin.exports.store'), [
        'kind' => 'csv',
        'password' => $secret,
    ])->assertSessionHasNoErrors();

    // The value is not part of the form any more, so it must not survive into
    // anything the request wrote.
    $written = array_merge(
        Export::sole()->getAttributes(),
        AuditLog::where('action', 'export_requested')->sole()->getAttributes(),
    );

    foreach ($written as $column => $value) {
        expect((string) $value)->not->toContain($secret, "leaked into [{$column}]");
    }
});

test('the request audit entry names the admin, format, filters, count and time', function () {
    Queue::fake();

    $admin = actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG']);

    Enrolee::factory()->forProgram($program)->submitted()->count(3)->create();
    Enrolee::factory()->submitted()->count(2)->create();

    $before = now()->subSecond();

    $this->post(route('admin.exports.store'), [
        'kind' => 'xlsx',
        'program_id' => $program->id,
        'status' => 'submitted',
    ])->assertSessionHasNoErrors();

    $entry = AuditLog::where('action', 'export_requested')->sole();

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->actor_id)->toBe($admin->id)
        ->and($entry->program_id)->toBe($program->id)
        ->and($entry->new_values['kind'])->toBe('xlsx')
        ->and($entry->new_values['filters']['program_id'])->toBe($program->id)
        ->and($entry->new_values['filters']['status'])->toBe('submitted')
        ->and($entry->new_values['requested_by'])->toBe($admin->id)
        ->and($entry->new_values['requested_by_email'])->toBe($admin->email)
        ->and($entry->new_values['record_count'])->toBe(3)
        ->and($entry->created_at->gte($before))->toBeTrue()
        ->and($entry->new_values['requested_at'])->toBeString();

    // The count is the number of records actually in scope, not the whole
    // table: this request filtered to one program of five rows.
    expect(Enrolee::count())->toBe(5);
});

test('every download is recorded with the count and the time', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin, ['status' => 'ready', 'kind' => 'csv', 'row_count' => 1_200]);

    Storage::disk('private')->put($export->path, "id\n1\n");

    foreach (range(1, 2) as $ignored) {
        $this->get(route('admin.exports.download', $export))->assertOk();
    }

    $entries = AuditLog::where('action', 'export_downloaded')->get();

    // Two downloads, two entries: re-collecting an artefact is exactly what an
    // audit has to be able to show.
    expect($entries)->toHaveCount(2)
        ->and($entries->first()->new_values['kind'])->toBe('csv')
        ->and($entries->first()->new_values['record_count'])->toBe(1200)
        ->and($entries->first()->new_values['downloaded_at'])->toBeString();
});

test('queueing an export is rate limited', function () {
    // The password field used to be the reason for this throttle. It is still
    // needed for the reason it now documents: each accepted request queues a
    // job that reads a slice of the whole table, so an unbounded form is a way
    // to fill the queue.
    actingAsAdmin();

    foreach (range(1, 11) as $ignored) {
        $this->post(route('admin.exports.store'), ['kind' => 'csv']);
    }

    $this->post(route('admin.exports.store'), ['kind' => 'csv'])->assertStatus(429);

    // The eleventh was refused and the first ten were not: the limit is a cap on
    // how many expensive jobs one session can queue, not a blanket refusal.
    expect(Export::count())->toBe(10);
});

/*
|--------------------------------------------------------------------------
| What the export screen tells the administrator
|--------------------------------------------------------------------------
*/

test('the whole range can be filtered by date inclusively', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'CEMOG']);

    // created_at is not mass-assignable, so it is set explicitly on rows whose
    // timestamp is the point of the test.
    $yesterday = Enrolee::factory()->forProgram($program)->create(['surname' => 'Yesterday']);
    $yesterday->forceFill(['created_at' => now()->startOfDay()->subDay()])->save();

    $today = Enrolee::factory()->forProgram($program)->create(['surname' => 'Today']);
    $today->forceFill(['created_at' => now()])->save();

    // "Registered from" starts at midnight, so a row saved at 23:59:59 on the
    // day itself is inside the range rather than one second outside it.
    $this->get(route('admin.exports.index', ['from' => now()->toDateString()]))
        ->assertOk()
        ->assertSee('1 enrolees match the current filters.');

    // "Registered to" runs to the end of the day, which is what makes an export
    // taken "to today" include everything registered today.
    $this->get(route('admin.exports.index', ['to' => now()->toDateString()]))
        ->assertOk()
        ->assertSee('2 enrolees match the current filters.');

    $this->get(route('admin.exports.index', [
        'from' => now()->subDay()->toDateString(),
        'to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertSee('2 enrolees match the current filters.');
});

test('the queued job applies the same inclusive range as the screen', function () {
    $admin = actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'CEMOG']);

    $yesterday = Enrolee::factory()->forProgram($program)->create(['surname' => 'Yesterday']);
    $yesterday->forceFill(['created_at' => now()->startOfDay()->subDay()])->save();

    // The edge this is really about: the last second of the end day.
    $lastSecond = Enrolee::factory()->forProgram($program)->create(['surname' => 'LastSecond']);
    $lastSecond->forceFill(['created_at' => now()->startOfDay()->addHours(23)->addMinutes(59)->addSeconds(59)])->save();

    $tomorrow = Enrolee::factory()->forProgram($program)->create(['surname' => 'Tomorrow']);
    $tomorrow->forceFill(['created_at' => now()->startOfDay()->addDay()])->save();

    $export = fakeExport($admin, ['status' => 'pending']);

    $export = runExportJob($export, [
        'program_id' => $program->id,
        'from' => now()->toDateString(),
        'to' => now()->toDateString(),
    ]);

    expect($export->row_count)->toBe(1);

    $csv = Storage::disk('private')->get($export->path);

    expect($csv)->toContain('LastSecond')
        ->and($csv)->not->toContain('Yesterday')
        ->and($csv)->not->toContain('Tomorrow');
});

test('active filters are shown as chips above the count', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'CEMOG']);
    $lga = Lga::factory()->create(['name' => 'Kano Municipal']);

    $response = $this->get(route('admin.exports.index', [
        'program_id' => $program->id,
        'status' => 'submitted',
        'lga_id' => $lga->id,
        'search' => 'Bello',
        'from' => '2026-10-01',
        'to' => '2026-10-02',
    ]))->assertOk();

    // Each filter is stated in words rather than left to be read off the form.
    $response->assertSee('Active filters:');
    $response->assertSee('Program: CEMOG');
    $response->assertSee('Status: Submitted');
    $response->assertSee('LGA: Kano Municipal');
    $response->assertSee('Search: &quot;Bello&quot;', escape: false);
    $response->assertSee('Registered from: 2026-10-01');
    $response->assertSee('Registered to: 2026-10-02');
});

test('a chip removes only its own filter', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'CEMOG']);

    $response = $this->get(route('admin.exports.index', [
        'program_id' => $program->id,
        'status' => 'submitted',
        'from' => '2026-10-01',
    ]))->assertOk();

    // Dropping status must leave the program and the date range behind, or the
    // count would widen by far more than the person clicking asked for.
    $withoutStatus = route('admin.exports.index', [
        'program_id' => $program->id,
        'from' => '2026-10-01',
    ]);

    $response->assertSee('Remove filter: Status: Submitted', escape: false);
    $response->assertSee($withoutStatus);

    $response->assertSee('Remove filter: Program: CEMOG', escape: false);
    $response->assertSee(
        route('admin.exports.index', ['status' => 'submitted', 'from' => '2026-10-01']),
    );
});

test('with no filters there are no chips to remove', function () {
    actingAsAdmin();

    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertDontSee('Active filters:', escape: false)
        ->assertDontSee('Clear all');
});

test('the format dropdown offers CSV, XLSX and Photos (ZIP)', function () {
    actingAsAdmin();

    $response = $this->get(route('admin.exports.index'))->assertOk();

    $response->assertSee('CSV');
    $response->assertSee('XLSX');
    $response->assertSee('Photos (ZIP)');

    // The three values the request accepts, and nothing else.
    foreach (['csv', 'xlsx', 'photos'] as $kind) {
        $response->assertSee('<option value="'.$kind.'"', escape: false);
    }
});

test('the export screen says all programs rather than whole-country', function () {
    actingAsAdmin();

    $response = $this->get(route('admin.exports.index'))->assertOk();

    $response->assertSee('All programs');
    $response->assertDontSee('whole-country', escape: false);
});

test('with no filters set the screen quotes the total', function () {
    actingAsAdmin();

    Enrolee::factory()->count(7)->create();

    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertSee('7 enrolees in total.');
});

test('with filters set the screen says what they match', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'CEMOG']);

    // forProgram, not forUser: an admin has no program_id, so forUser would
    // attach these to a fresh program and the filter would match nothing.
    Enrolee::factory()->forProgram($program)->submitted()->count(3)->create();
    // Outside the filter, so the count must not include them.
    Enrolee::factory()->count(4)->create();

    $this->get(route('admin.exports.index', ['program_id' => $program->id, 'status' => 'submitted']))
        ->assertOk()
        ->assertSee('3 enrolees match the current filters.')
        ->assertDontSee('7 enrolees in total.');
});

test('the count follows the filters even after a rejected submission', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'CEMOG']);

    Enrolee::factory()->forProgram($program)->draft()->count(2)->create();
    Enrolee::factory()->count(5)->create();

    // A rejected submission redisplays the form from old input, so the filters
    // live in the session rather than the query string. The count must follow
    // them anyway, or it would quote a total for filters that are no longer on
    // screen.
    $this->post(route('admin.exports.store'), [
        'kind' => 'pdf',
        'program_id' => $program->id,
    ])->assertSessionHasErrors('kind');

    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertSee('2 enrolees match the current filters.');
});

test('your exports lists each status, the filters, the time and a download', function () {
    $admin = actingAsAdmin();
    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'CEMOG']);

    fakeExport($admin, ['kind' => 'csv', 'status' => 'pending', 'path' => null, 'filters' => []]);
    fakeExport($admin, [
        'kind' => 'xlsx',
        'status' => 'processing',
        'path' => null,
        'filters' => ['program_id' => $program->id, 'status' => 'submitted'],
    ]);
    fakeExport($admin, [
        'kind' => 'photos',
        'status' => 'failed',
        'path' => null,
        'filters' => [],
        'error' => 'That filter matches too many enrolees.',
    ]);
    $ready = fakeExport($admin, [
        'kind' => 'csv',
        'status' => 'ready',
        'path' => 'exports/mine.csv',
        'filters' => ['program_id' => $program->id],
    ]);

    $response = $this->get(route('admin.exports.index'))->assertOk();

    // Every status the job can be in is shown by name, not as a raw column.
    $response->assertSee('Pending');
    $response->assertSee('Processing');
    $response->assertSee('Failed');
    $response->assertSee('Ready');

    // The filters, resolved from stored ids into something readable.
    $response->assertSee('Program: CEMOG');
    $response->assertSee('Status: Submitted');
    $response->assertSee('All programs');

    // The time the request was made, and a download link for the ready one.
    $response->assertSee($ready->created_at->format('j M Y'));
    $response->assertSee(route('admin.exports.download', $ready), escape: false);
});

test('an export that is not ready offers no download link', function () {
    $admin = actingAsAdmin();

    $pending = fakeExport($admin, ['status' => 'pending', 'path' => null]);

    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertDontSee(route('admin.exports.download', $pending), escape: false)
        ->assertSee('Not available yet');
});

test('the export page shows the last export and a history of completed ones', function () {
    $admin = actingAsAdmin();

    $done = fakeExport($admin, ['row_count' => 1248, 'size_bytes' => 2048]);

    // Built after the completed one, so a history that sorted or filtered
    // wrongly would show one of these instead of it.
    fakeExport($admin, ['status' => 'pending', 'row_count' => 11111, 'path' => null]);
    fakeExport($admin, ['status' => 'failed', 'row_count' => 22222, 'path' => null]);

    $response = $this->get(route('admin.exports.index'))->assertOk();

    $response->assertSee('Last export')
        ->assertSee('Export history')
        ->assertSee('1,248')
        ->assertSee($done->created_at->format('d M Y, H:i'))
        ->assertSee($admin->name);

    // Exactly one history row: the pending request and the failed one
    // produced no file, so neither belongs in a table of what was actually
    // taken out of the system. They remain visible under "Your exports".
    expect(substr_count($response->getContent(), 'data-label="Records"'))->toBe(1);

    $response->assertDontSee('11,111')
        ->assertDontSee('22,222');
});

/*
|--------------------------------------------------------------------------
| Progress, and what the job is allowed to do
|--------------------------------------------------------------------------
|
| A queued export is invisible work: the administrator's only feedback for
| minutes is a status word. These tests pin the progress figures the page
| renders, and the job's own limits — the ones that decide whether a heavy
| export is killed by the host, retried into a duplicate, or allowed to finish.
|
*/

test('the job reports rows processed against the total as it runs', function () {
    $admin = actingAsAdmin();

    Enrolee::factory()->count(7)->create();

    $export = fakeExport($admin, ['status' => 'pending']);

    $seen = [];

    // The service is asked for progress per page; a 7-row export is one page, so
    // the callback reports the opening zero and the finished count.
    (new GenerateExport($export->id))->handle(
        app(ExportService::class),
        app(PhotoService::class),
    );

    $export->refresh();

    expect($export->status)->toBe('ready')
        ->and($export->row_count)->toBe(7)
        ->and($export->total_count)->toBe(7)
        ->and($export->processed_count)->toBe(7);

    $seen = [$export->processed_count, $export->total_count];
    expect($seen)->toBe([7, 7]);
});

test('progress is reported page by page, starting from zero', function () {
    $admin = actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG']);

    // Inserted rather than built through Enrolee::factory(), which cannot make
    // this many rows: every enrolee it creates brings its own program, user,
    // facility, ward and LGA, and each of those names is drawn from a Faker pool
    // small enough to collide long before 501 rows. That is a property of the
    // fixtures, not of the export, so the rows are written straight to the table
    // and the export path is left to prove itself. One page is 500 rows, so this
    // is two full pages and one part.
    $rows = [];

    foreach (range(1, 501) as $i) {
        $rows[] = [
            'program_id' => $program->id,
            'created_by' => $admin->id,
            'status' => 'submitted',
            'surname' => "Bulk{$i}",
            'first_name' => 'Test',
            'gender' => 'female',
            'date_of_birth' => '1990-05-17',
            'nin' => (string) (10000000000 + $i),
            'phone' => '080'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            'address' => 'Somewhere',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('enrolees')->insert($rows);

    $reports = [];

    app(ExportService::class)->toCsv(
        Enrolee::query(),
        $path = tempnam(sys_get_temp_dir(), 'export-progress'),
        function (int $processed, int $total) use (&$reports) {
            $reports[] = [$processed, $total];
        },
    );

    @unlink($path);

    expect($reports)->not->toBeEmpty()
        // 501 rows at 500 a page is two pages, so the callback fires once per
        // page and once before the first: page-by-page, not one final number.
        ->and($reports)->toHaveCount(3)
        // The first report is before any row is written, so a page rendering
        // "Processed 0 of 501" has a real zero rather than a blank.
        ->and($reports[0])->toBe([0, 501])
        ->and(end($reports))->toBe([501, 501])
        // Every report carries the same total: a progress bar whose denominator
        // moves is worse than none.
        ->and(array_unique(array_column($reports, 1)))->toBe([501])
        // Monotonically increasing, so the number never goes backwards.
        ->and(array_column($reports, 0))->toBe([0, 500, 501]);
});

test('a queued export shows a progress line and refreshes itself', function () {
    $admin = actingAsAdmin();

    $processing = fakeExport($admin, [
        'status' => 'processing',
        'path' => null,
        'processed_count' => 1_200,
        'total_count' => 5_000,
    ]);

    $ready = fakeExport($admin, [
        'status' => 'ready',
        'processed_count' => 40,
        'total_count' => 40,
    ]);

    $response = $this->get(route('admin.exports.index'))->assertOk();

    $response->assertSee('Processed 1,200 of 5,000');
    $response->assertSee('(24%)');

    // The refresh is driven by the presence of a running row, and only then.
    expect($response->getContent())
        ->toContain('data-export-progress')
        ->and($processing->progressLabel())->toBe('Processed 1,200 of 5,000')
        ->and($ready->isActive())->toBeFalse();
});

test('a finished export does not offer progress, and a queued one admits no total', function () {
    $admin = actingAsAdmin();

    $ready = fakeExport($admin, ['status' => 'ready', 'processed_count' => 10, 'total_count' => 10]);
    $pending = fakeExport($admin, ['status' => 'pending', 'processed_count' => null, 'total_count' => null]);

    expect($ready->isActive())->toBeFalse()
        ->and($pending->isActive())->toBeTrue()
        // No total yet means no invented percentage.
        ->and($pending->progressLabel())->toBeNull()
        ->and($pending->progressPercent())->toBeNull();

    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertSee('Waiting for a worker');
});

test('the job is given a ten minute ceiling and one attempt', function () {
    $job = new GenerateExport(1);

    // One attempt: a retry would restart the whole export from row zero while
    // the page still says "processing", leaving a half-written file behind.
    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(600)
        ->and($job->jobTimeout)->toBe(600);
});

test('an XLSX export produces a real, readable workbook', function () {
    $admin = actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG']);

    Enrolee::factory()->forProgram($program)->create(['surname' => 'Workbook']);

    $export = fakeExport($admin, ['kind' => 'xlsx', 'status' => 'pending']);

    // XLSX was offered by the form and accepted by the validator for the whole
    // life of this feature while failing to build, because every XLSX test set
    // the row's status by hand instead of running the job. This one runs it and
    // then opens the result, so a broken writer cannot pass again.
    runExportJob($export);

    $export->refresh();

    expect($export->status)->toBe('ready')
        ->and($export->row_count)->toBe(1)
        ->and($export->error)->toBeNull()
        ->and($export->size_bytes)->toBeGreaterThan(0);

    $absolute = Storage::disk('private')->path($export->path);

    // An XLSX is a zip of XML parts; if the writer produced something else, the
    // header row would be missing and the archive would not open at all.
    expect(substr((string) file_get_contents($absolute), 0, 2))->toBe('PK');

    $zip = new ZipArchive;
    expect($zip->open($absolute))->toBeTrue();
    expect($zip->locateName('xl/workbook.xml'))->not->toBeFalse();

    // OpenSpout writes cell text inline in the sheet part rather than into a
    // shared-string table, so the header has to be looked for there.
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

    $zip->close();

    expect($sheet)->toContain('surname')
        ->and($sheet)->toContain('Workbook');
});

test('a photo bundle stores its entries without recompressing them', function () {
    $admin = actingAsAdmin();

    $photo = Enrolee::factory()->create(['nin' => '12345678901', 'photo_path' => 'photos/present.jpg']);

    // A real JPEG on the fake disk, so the archive has genuine bytes to store.
    $bytes = (function () {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($image, null, 80);

        return ob_get_clean();
    })();

    Storage::disk('private')->put($photo->photo_path, $bytes);

    $export = fakeExport($admin, ['kind' => 'photos', 'status' => 'pending']);

    runExportJob($export);

    $export->refresh();

    expect($export->status)->toBe('ready')->and($export->row_count)->toBe(1);

    $absolute = Storage::disk('private')->path($export->path);

    $zip = new ZipArchive;
    expect($zip->open($absolute))->toBeTrue();

    $name = $zip->getNameIndex(0);
    $stat = $zip->statIndex(0);

    expect($stat['comp_method'])->toBe(ZipArchive::CM_STORE)
        // Stored, not deflated: the archive is the size of the photographs
        // themselves, not smaller-but-slower.
        ->and($stat['size'])->toBe(strlen($bytes))
        ->and($zip->getFromIndex(0))->toBe($bytes);

    $zip->close();
});

test('a photograph missing from disk is logged and skipped, not fatal', function () {
    $admin = actingAsAdmin();

    $present = Enrolee::factory()->create(['nin' => '11111111111', 'photo_path' => 'photos/here.jpg']);
    $missing = Enrolee::factory()->create(['nin' => '22222222222', 'photo_path' => 'photos/gone.jpg']);

    Storage::disk('private')->put($present->photo_path, 'not-really-a-jpeg');

    // Recorded on the row but never written: exactly the state a restored
    // database and a cleared disk leave behind.
    expect(filled($missing->photo_path))->toBeTrue();

    $export = fakeExport($admin, ['kind' => 'photos', 'status' => 'pending']);

    Log::spy();

    runExportJob($export);

    $export->refresh();

    expect($export->status)->toBe('ready')
        ->and($export->row_count)->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'missing')
            && ($context['enrolee_id'] ?? null) === $missing->id);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('private')->path($export->path));

    expect($zip->numFiles)->toBe(1);

    $zip->close();
});

test('a job no worker has touched is built when the export page is opened', function () {
    $admin = actingAsAdmin();

    Enrolee::factory()->create();

    // phpunit.xml swaps the app onto the sync driver, which would run the job
    // during dispatch and never queue it. The production queue driver is what
    // is being tested here.
    config(['queue.default' => 'database']);

    $export = fakeExport($admin, [
        'status' => 'pending',
        'path' => null,
        'row_count' => null,
        'size_bytes' => null,
    ]);

    GenerateExport::dispatch($export->id, $export->filters ?? []);

    // The page-load fallback only takes work that has waited 90 seconds —
    // younger jobs belong to the cron worker.
    DB::table('jobs')->update(['available_at' => time() - 120]);

    $this->get(route('admin.exports.index'))->assertOk();

    expect($export->refresh()->status)->toBe('ready')
        ->and($export->row_count)->toBe(1)
        ->and($export->size_bytes)->toBeGreaterThan(0)
        ->and(Storage::disk('private')->exists($export->path))->toBeTrue();
});

test('a freshly queued job is left for the real worker, not taken by the page', function () {
    $admin = actingAsAdmin();

    config(['queue.default' => 'database']);

    $export = fakeExport($admin, ['status' => 'pending', 'path' => null]);

    GenerateExport::dispatch($export->id, []);

    $this->get(route('admin.exports.index'))->assertOk();

    // Under the 90 seconds the fallback waits, the page renders as waiting
    // and the job stays in the queue for cron to pick up.
    expect($export->refresh()->status)->toBe('pending')
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('an export whose queued job is gone fails instead of pending for ever', function () {
    $admin = actingAsAdmin();

    $export = fakeExport($admin, ['status' => 'pending', 'path' => null]);

    // Old enough to be past the row-then-job race, with nothing in the queue
    // to ever run it: the state a cleared jobs table leaves behind.
    $export->forceFill(['updated_at' => now()->subMinutes(10)])->save();

    $response = $this->get(route('admin.exports.index'))->assertOk();

    expect($export->refresh()->status)->toBe('failed')
        ->and($export->error)->toContain('Please request it again');

    $response->assertSee('Failed');
});
