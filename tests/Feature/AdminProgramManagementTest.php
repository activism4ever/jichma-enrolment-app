<?php

use App\Models\AuditLog;
use App\Models\Enrolee;
use App\Models\Export;
use App\Models\Program;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Programme management
|--------------------------------------------------------------------------
|
| Programmes are the unit of scope for enrolments, accounts and exports, so
| these tests lean on the rules that are easy to get wrong: the immutable code,
| the audited sensitivity flag, and the distinction between a programme being
| deactivated (no new records) and its history surviving.
|
*/

function programmePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'NEWPROG',
        'name' => 'New Programme',
        'description' => 'A programme for testing.',
        'is_sensitive' => '0',
    ], $overrides);
}

/* ------------------------------------------------------------------ */
/* Access                                                             */
/* ------------------------------------------------------------------ */

test('only an administrator can reach programme management', function () {
    foreach ([actingAsProgramUser(), actingAsReviewer()] as $user) {
        $this->actingAs($user)->get(route('admin.programs.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.programs.create'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.programs.store'), programmePayload())->assertForbidden();
    }
});

test('a guest is sent to the login page', function () {
    $this->get(route('admin.programs.index'))->assertRedirect(route('login'));
});

test('an administrator sees the programme list', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'CEMOG']);

    $this->get(route('admin.programs.index'))
        ->assertOk()
        ->assertSee('CEMOG')
        ->assertSee('New programme');
});

test('a programme user cannot deactivate or reactivate a programme', function () {
    $program = Program::factory()->create();

    actingAsProgramUser();

    $this->delete(route('admin.programs.destroy', $program))->assertForbidden();
    $this->post(route('admin.programs.activate', $program))->assertForbidden();

    expect($program->refresh()->is_active)->toBeTrue();
});

test('a forbidden edit leaves the programme and the audit trail untouched', function () {
    $program = Program::factory()->create(['code' => 'LOCKED', 'name' => 'Original']);

    foreach ([actingAsProgramUser(), actingAsReviewer()] as $user) {
        $this->put(route('admin.programs.update', $program), [
            'name' => 'Hijacked',
            'description' => 'Changed',
            'is_sensitive' => '1',
        ])->assertForbidden();
    }

    // No write happened, and none was recorded as having happened.
    expect($program->refresh()->name)->toBe('Original')
        ->and($program->is_sensitive)->toBeFalse()
        ->and($program->description)->toBeNull()
        ->and(AuditLog::where('action', 'program_updated')->exists())->toBeFalse();
});

test('a failed edit leaves the programme unchanged', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'KEEPTXT', 'name' => 'Stays', 'is_sensitive' => false]);

    $this->put(route('admin.programs.update', $program), [
        'name' => '',
        'description' => 'New description',
        'is_sensitive' => '1',
    ])->assertSessionHasErrors('name');

    // Validation failed, so nothing was saved and nothing was audited: a
    // mistake on the form cannot quietly change the programme behind the error.
    expect($program->refresh()->name)->toBe('Stays')
        ->and($program->is_sensitive)->toBeFalse()
        ->and($program->description)->toBeNull()
        ->and(AuditLog::where('action', 'program_updated')->exists())->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* Creation                                                           */
/* ------------------------------------------------------------------ */

test('an administrator can create a programme', function () {
    actingAsAdmin();

    $this->post(route('admin.programs.store'), programmePayload([
        'code' => 'newprog',
        'name' => 'Newly Added',
    ]))->assertRedirect(route('admin.programs.index'));

    $program = Program::where('code', 'NEWPROG')->sole();

    // Code is normalised to upper case, active by default, non-sensitive by
    // default: only an explicit act marks a programme sensitive.
    expect($program->code)->toBe('NEWPROG')
        ->and($program->name)->toBe('Newly Added')
        ->and($program->is_active)->toBeTrue()
        ->and($program->is_sensitive)->toBeFalse();
});

test('creating a programme is audited', function () {
    actingAsAdmin();

    $this->post(route('admin.programs.store'), programmePayload(['code' => 'AUDITME']))
        ->assertRedirect();

    $log = AuditLog::where('action', 'program_created')->sole();

    expect($log->new_values['code'])->toBe('AUDITME')
        ->and($log->new_values['is_active'])->toBeTrue()
        ->and($log->new_values['is_sensitive'])->toBeFalse();
});

test('a programme can be created already marked sensitive and is audited', function () {
    actingAsAdmin();

    $this->post(route('admin.programs.store'), programmePayload([
        'code' => 'SENS',
        'is_sensitive' => '1',
    ]))->assertRedirect();

    expect(Program::where('code', 'SENS')->sole()->is_sensitive)->toBeTrue();

    expect(AuditLog::where('action', 'program_created')->sole()->new_values['is_sensitive'])->toBeTrue();
});

test('a programme code is required and must be unique', function () {
    actingAsAdmin();

    Program::factory()->create(['code' => 'TAKEN']);

    $this->post(route('admin.programs.store'), programmePayload(['code' => '']))
        ->assertSessionHasErrors('code');

    $this->post(route('admin.programs.store'), programmePayload(['code' => 'taken']))
        ->assertSessionHasErrors('code');

    expect(Program::where('code', 'TAKEN')->count())->toBe(1);
});

test('a programme code rejects characters that would be unsafe in a filename', function () {
    actingAsAdmin();

    $this->post(route('admin.programs.store'), programmePayload(['code' => 'bad code!']))
        ->assertSessionHasErrors('code');

    expect(Program::count())->toBe(0);
});

test('the sensitivity box cannot be omitted when creating a programme', function () {
    actingAsAdmin();

    $this->post(route('admin.programs.store'), [
        'code' => 'NOPLAG',
        'name' => 'No flag',
        'description' => '',
    ])->assertSessionHasErrors('is_sensitive');

    expect(Program::where('code', 'NOPLAG')->exists())->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* Rename, code immutability and sensitivity                          */
/* ------------------------------------------------------------------ */

test('a programme can be renamed and the change is audited', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'CEMOG', 'name' => 'Old Name']);

    $this->put(route('admin.programs.update', $program), [
        'name' => 'New Name',
        'description' => 'Updated description',
        'is_sensitive' => '0',
    ])->assertRedirect(route('admin.programs.index'));

    expect($program->refresh()->name)->toBe('New Name');

    $log = AuditLog::where('action', 'program_updated')->sole();

    expect($log->old_values['name'])->toBe('Old Name')
        ->and($log->new_values['name'])->toBe('New Name');
});

test('renaming a programme keeps its records and accounts attached', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'KEEPREL', 'name' => 'Before']);
    $enrolee = Enrolee::factory()->forProgram($program)->create();
    $user = User::factory()->programUser($program)->create();

    $this->put(route('admin.programs.update', $program), [
        'name' => 'After',
        'description' => '',
        'is_sensitive' => '0',
    ])->assertRedirect();

    // Foreign keys point at the id, so a rename moves nothing: no re-parenting,
    // no re-statusing, no duplicates.
    expect($enrolee->refresh()->program_id)->toBe($program->id)
        ->and($user->refresh()->program_id)->toBe($program->id)
        // And the new name resolves through the same relationship, so every
        // screen that prints "code — name" picks up the rename in one place.
        ->and($enrolee->program->name)->toBe('After')
        ->and($user->program->name)->toBe('After');
});

test('the programme code cannot be changed through an edit', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'KEEP', 'name' => 'Keep']);

    // A crafted request supplies a different code; UpdateProgramRequest does
    // not read it, so it must have no effect.
    $this->put(route('admin.programs.update', $program), [
        'code' => 'CHANGED',
        'name' => 'Keep',
        'description' => '',
        'is_sensitive' => '0',
    ])->assertRedirect();

    expect($program->refresh()->code)->toBe('KEEP');
});

test('changing the sensitivity setting is audited', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'STANDARD', 'is_sensitive' => false]);

    $this->put(route('admin.programs.update', $program), [
        'name' => $program->name,
        'description' => '',
        'is_sensitive' => '1',
    ])->assertRedirect();

    expect($program->refresh()->is_sensitive)->toBeTrue();

    $log = AuditLog::where('action', 'program_updated')->sole();

    expect($log->old_values['is_sensitive'])->toBeFalse()
        ->and($log->new_values['is_sensitive'])->toBeTrue();
});

test('an unchanged programme edit writes no audit entry', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'SAME', 'name' => 'Same']);

    $this->put(route('admin.programs.update', $program), [
        'name' => 'Same',
        'description' => $program->description,
        'is_sensitive' => $program->is_sensitive ? '1' : '0',
    ])->assertRedirect();

    expect(AuditLog::where('action', 'program_updated')->exists())->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* Deactivation                                                       */
/* ------------------------------------------------------------------ */

test('deactivating a programme keeps the row and is audited', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'RETIRE']);

    $this->delete(route('admin.programs.destroy', $program))
        ->assertRedirect(route('admin.programs.index'));

    // Never deleted: enrolees, accounts and the audit trail reference it.
    expect(Program::find($program->id))->not->toBeNull()
        ->and($program->refresh()->is_active)->toBeFalse();

    expect(AuditLog::where('action', 'program_deactivated')->exists())->toBeTrue();
});

test('a deactivated programme can be reactivated and is audited', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'RETIRED']);
    $program->forceFill(['is_active' => false])->save();

    $this->post(route('admin.programs.activate', $program))->assertRedirect();

    expect($program->refresh()->is_active)->toBeTrue();

    expect(AuditLog::where('action', 'program_activated')->exists())->toBeTrue();
});

test('reactivating a programme does not create a duplicate', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'ONCE']);
    $program->forceFill(['is_active' => false])->save();

    $this->post(route('admin.programs.activate', $program))->assertRedirect();

    // Activation flips the flag on the existing row; it never inserts a new one.
    expect(Program::where('code', 'ONCE')->count())->toBe(1)
        ->and(Program::find($program->id)->is_active)->toBeTrue();
});

test('deactivating a programme leaves its enrolees and accounts intact', function () {
    actingAsAdmin();

    $program = Program::factory()->create(['code' => 'HISTORY']);
    $enrolee = Enrolee::factory()->forProgram($program)->create();
    $user = User::factory()->programUser($program)->create();

    $this->delete(route('admin.programs.destroy', $program))->assertRedirect();

    expect(Enrolee::find($enrolee->id))->not->toBeNull()
        ->and(User::find($user->id))->not->toBeNull()
        ->and($enrolee->refresh()->program_id)->toBe($program->id)
        ->and($user->refresh()->program_id)->toBe($program->id);
});

/* ------------------------------------------------------------------ */
/* Deactivation excludes new work                                     */
/* ------------------------------------------------------------------ */

test('a deactivated programme is not offered when registering an enrolee', function () {
    actingAsAdmin();

    Program::factory()->create(['code' => 'LIVEONE']);
    Program::factory()->inactive()->create(['code' => 'RETIRED']);

    $this->get(route('enrolees.create'))
        ->assertOk()
        ->assertSee('LIVEONE')
        ->assertDontSee('RETIRED');
});

test('an administrator cannot register an enrolee into a deactivated programme', function () {
    actingAsAdmin();

    $retired = Program::factory()->inactive()->create(['code' => 'RETIRED']);

    $this->post(route('enrolees.store'), $this->validEnroleePayload([
        'program_id' => $retired->id,
    ]))->assertSessionHasErrors('program_id');

    expect(Enrolee::count())->toBe(0);
});

test('a deactivated programme is not offered when creating an account', function () {
    actingAsAdmin();

    Program::factory()->create(['code' => 'LIVEONE']);
    Program::factory()->inactive()->create(['code' => 'RETIRED']);

    $this->get(route('admin.users.create'))
        ->assertOk()
        ->assertSee('LIVEONE')
        ->assertDontSee('RETIRED');
});

test('an account cannot be created in a deactivated programme', function () {
    actingAsAdmin();

    $retired = Program::factory()->inactive()->create(['code' => 'RETIRED']);

    $this->post(route('admin.users.store'), [
        'name' => 'Blocked Person',
        'email' => 'blocked@example.test',
        'role' => 'program_user',
        'program_id' => $retired->id,
        'password' => 'Temporary1pass',
        'password_confirmation' => 'Temporary1pass',
    ])->assertSessionHasErrors('program_id');

    expect(User::where('email', 'blocked@example.test')->exists())->toBeFalse();
});

test('an account already in a deactivated programme can still be edited', function () {
    actingAsAdmin();

    $retired = Program::factory()->inactive()->create(['code' => 'RETIRED']);
    $user = User::factory()->programUser($retired)->create(['name' => 'Before']);

    // The programme is unchanged, so its inactivity must not block an
    // unrelated edit such as a rename.
    $this->put(route('admin.users.update', $user), [
        'name' => 'After',
        'email' => $user->email,
        'role' => 'program_user',
        'program_id' => $retired->id,
        'is_active' => '1',
    ])->assertRedirect();

    expect($user->refresh()->name)->toBe('After')
        ->and($user->program_id)->toBe($retired->id);
});

test('an account cannot be moved into a deactivated programme', function () {
    actingAsAdmin();

    $active = Program::factory()->create(['code' => 'LIVEONE']);
    $retired = Program::factory()->inactive()->create(['code' => 'RETIRED']);
    $user = User::factory()->programUser($active)->create();

    $this->put(route('admin.users.update', $user), [
        'name' => $user->name,
        'email' => $user->email,
        'role' => 'program_user',
        'program_id' => $retired->id,
        'is_active' => '1',
    ])->assertSessionHasErrors('program_id');

    expect($user->refresh()->program_id)->toBe($active->id);
});

test('a deactivated programme is excluded from a new export', function () {
    actingAsAdmin();

    Program::factory()->create(['code' => 'LIVEONE']);
    $retired = Program::factory()->inactive()->create(['code' => 'RETIRED']);

    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertSee('LIVEONE')
        ->assertDontSee('RETIRED');

    $this->post(route('admin.exports.store'), [
        'kind' => 'csv',
        'program_id' => $retired->id,
    ])->assertSessionHasErrors('program_id');
});

/* ------------------------------------------------------------------ */
/* Historical preservation                                            */
/* ------------------------------------------------------------------ */

test('a deactivated programme stays in the enrolee filter', function () {
    actingAsAdmin();

    Program::factory()->inactive()->create(['code' => 'RETIRED']);

    // Filtering history is the whole reason the programme must remain listed.
    $this->get(route('enrolees.index'))
        ->assertOk()
        ->assertSee('RETIRED');
});

test('a deactivated programme stays on the programme summary', function () {
    actingAsAdmin();

    $program = Program::factory()->inactive()->create(['code' => 'RETIRED', 'name' => 'Retired Programme']);
    Enrolee::factory()->forProgram($program)->create();

    $this->get(route('programme-summary.index'))
        ->assertOk()
        ->assertSee('RETIRED')
        ->assertSee('Deactivated');
});

test('a deactivated programme stays in the audit log filter', function () {
    actingAsAdmin();

    Program::factory()->inactive()->create(['code' => 'RETIRED']);

    $this->get(route('admin.audit.index'))
        ->assertOk()
        ->assertSee('RETIRED');
});

test('a deactivated programme keeps its completed exports visible and downloadable', function () {
    Storage::fake('private');

    $admin = actingAsAdmin();
    $retired = Program::factory()->inactive()->create(['code' => 'RETIRED']);

    $export = Export::create([
        'uuid' => Export::newUuid(),
        'requested_by' => $admin->id,
        'kind' => 'csv',
        'status' => 'ready',
        'disk' => 'private',
        'path' => 'exports/retired-history.csv',
        'filters' => ['program_id' => $retired->id],
        'expires_at' => now()->addDay(),
    ]);

    Storage::disk('private')->put($export->path, "surname,nin\nBello,12345678901\n");

    // The deactivated programme is not offered for a new export, but the one
    // already requested is still listed — history names it — and still
    // collectable.
    $this->get(route('admin.exports.index'))
        ->assertOk()
        ->assertSee(route('admin.exports.download', $export), escape: false)
        ->assertSee('Program: RETIRED', escape: false);

    $this->get(route('admin.exports.download', $export))->assertOk();
});

test('a draft in a deactivated programme can still be submitted', function () {
    // A registration started while the programme was active is historical data:
    // completing it is not "selecting a deactivated programme for new
    // enrolment". Only new registrations are locked to active programmes.
    $program = Program::factory()->inactive()->create(['code' => 'RETIRED']);
    $user = actingAsProgramUser($program);

    $enrolee = Enrolee::factory()->forProgram($program)->forUser($user)
        ->draft()
        ->create(['photo_path' => 'photos/draft.jpg']);

    $this->post(route('enrolees.submit', $enrolee), $this->validEnroleePayload([
        'program_id' => $program->id,
    ]))->assertRedirect(route('enrolees.show', $enrolee));

    expect($enrolee->refresh()->status)->toBe(\App\Enums\EnroleeStatus::Submitted)
        ->and($enrolee->program_id)->toBe($program->id);
});

/* ------------------------------------------------------------------ */
/* Sensitivity is data, not a magic code                              */
/* ------------------------------------------------------------------ */

test('the sensitivity flag comes from the column, not the code', function () {
    actingAsAdmin();

    // A programme with a different code can be sensitive...
    $sensitive = Program::factory()->create(['code' => 'HEALTH', 'is_sensitive' => true]);
    // ...and PLHIV is only sensitive because the data says so.
    $plhiv = Program::factory()->plhiv()->create();

    expect($sensitive->isSensitive())->toBeTrue()
        ->and($plhiv->isSensitive())->toBeTrue();

    // Renaming PLHIV must not silently drop its protection.
    $plhiv->update(['name' => 'PLHIV Programme']);

    expect($plhiv->refresh()->isSensitive())->toBeTrue();
});

test('a new programme is not sensitive unless marked so', function () {
    actingAsAdmin();

    $this->post(route('admin.programs.store'), programmePayload(['code' => 'PLAIN']))
        ->assertRedirect();

    expect(Program::where('code', 'PLAIN')->sole()->isSensitive())->toBeFalse();
});
