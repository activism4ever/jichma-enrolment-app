<?php

use App\Models\Export;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Export housekeeping
|--------------------------------------------------------------------------
*/

test('pruning removes the file behind an expired export but keeps the row', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    $expired = Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'ready',
        'disk' => 'private',
        'path' => 'exports/gone.csv',
        'expires_at' => now()->subDays(3),
    ]);

    Storage::disk('private')->put('exports/gone.csv', 'surname,nin');

    $this->artisan('exports:prune')->assertSuccessful();

    Storage::disk('private')->assertMissing('exports/gone.csv');

    // The row survives: the audit trail must still show an export was produced.
    expect($expired->refresh())->not->toBeNull()
        ->and($expired->path)->toBeNull()
        ->and($expired->status)->toBe('ready');
});

test('pruning leaves an export that is still within its window', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    $export = Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'ready',
        'disk' => 'private',
        'path' => 'exports/current.csv',
        'expires_at' => now()->addHours(6),
    ]);

    Storage::disk('private')->put('exports/current.csv', 'surname,nin');

    $this->artisan('exports:prune')->assertSuccessful();

    Storage::disk('private')->assertExists('exports/current.csv');

    expect($export->refresh()->path)->not->toBeNull();
});

test('pruning corrects a row whose file has already gone', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    $export = Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'ready',
        'disk' => 'private',
        'path' => 'exports/never-existed.csv',
        'expires_at' => now()->subWeek(),
    ]);

    $this->artisan('exports:prune')->assertSuccessful();

    // Nothing to delete, but the row should stop claiming it has a file.
    expect($export->refresh()->path)->toBeNull();
});

test('a dry run changes nothing', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    $export = Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'ready',
        'disk' => 'private',
        'path' => 'exports/keep.csv',
        'expires_at' => now()->subDays(3),
    ]);

    Storage::disk('private')->put('exports/keep.csv', 'surname,nin');

    $this->artisan('exports:prune', ['--dry-run' => true])->assertSuccessful();

    Storage::disk('private')->assertExists('exports/keep.csv');
    expect($export->refresh()->path)->not->toBeNull();
});

test('pruning ignores failed exports unless asked', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    $export = Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'photos',
        'status' => 'failed',
        'disk' => 'private',
        'path' => 'exports/partial.zip',
        'expires_at' => now()->subDays(3),
        'error' => 'No enrolees matched',
    ]);

    Storage::disk('private')->put('exports/partial.zip', 'PK');

    $this->artisan('exports:prune')->assertSuccessful();
    Storage::disk('private')->assertExists('exports/partial.zip');

    $this->artisan('exports:prune', ['--include-failed' => true])->assertSuccessful();
    Storage::disk('private')->assertMissing('exports/partial.zip');
});

test('pruning collects the staging file of an export that never finished', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    // An export whose worker was killed outright: no exception ran, so the job
    // never deleted its own staging file, and the row has no path for the prune
    // command to work from. Nothing else in the application would ever remove it.
    Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'processing',
        'disk' => 'private',
        'path' => null,
        'processed_count' => 1500,
    ]);

    $abandoned = 'exports/tmp/'.Export::newUuid().'.part';

    Storage::disk('private')->put($abandoned, str_repeat('id,surname'.PHP_EOL, 200));

    // Backdate it past the age floor. The filesystem clock is not something a
    // test should be arguing with, so the file is touched rather than aged.
    touch(Storage::disk('private')->path($abandoned), now()->subDay()->getTimestamp());

    $this->artisan('exports:prune')
        ->expectsOutputToContain('1 partial file(s)')
        ->assertSuccessful();

    Storage::disk('private')->assertMissing($abandoned);
});

test('pruning never collects a staging file an export is still writing', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'processing',
        'disk' => 'private',
        'path' => null,
    ]);

    $inFlight = 'exports/tmp/'.Export::newUuid().'.part';

    Storage::disk('private')->put($inFlight, 'id,surname'.PHP_EOL.'1,HalfWritten'.PHP_EOL);

    // --days=0 means "remove everything already expired", which for a finished
    // file is safe. For a staging file it must still not reach into a job that is
    // running right now, which is why the floor is the job's own time limit.
    $this->artisan('exports:prune', ['--days' => 0])->assertSuccessful();

    Storage::disk('private')->assertExists($inFlight);
});

test('a dry run reports abandoned staging files without removing them', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();

    Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'photos',
        'status' => 'processing',
        'disk' => 'private',
        'path' => null,
    ]);

    $abandoned = 'exports/tmp/'.Export::newUuid().'.part';

    Storage::disk('private')->put($abandoned, 'PK'.str_repeat("\0", 1024));
    touch(Storage::disk('private')->path($abandoned), now()->subDay()->getTimestamp());

    $this->artisan('exports:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Would remove 1 partial file(s)')
        ->assertSuccessful();

    Storage::disk('private')->assertExists($abandoned);
});
