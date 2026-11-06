<?php

use App\Models\AuditLog;
use App\Models\Enrolee;
use App\Models\Facility;
use App\Models\Lga;
use App\Models\User;
use App\Models\Ward;

/*
|--------------------------------------------------------------------------
| Facility management and location counts
|--------------------------------------------------------------------------
|
| These tests cover both the LGA ward-count regression (withCount() being
| discarded by a later select()) and the administrator's single-facility
| workflow: add, view, rename, and move only when it is safe to do so.
|
*/

function facilityManagementPayload(Lga $lga, Ward $ward, array $overrides = []): array
{
    return array_merge([
        'lga_id' => $lga->id,
        'ward_id' => $ward->id,
        'name' => 'Sabon Gari Health Post',
    ], $overrides);
}

test('the LGA table reports the real ward, facility and registration counts', function () {
    actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    // A second ward under the same LGA, so the count cannot pass by accident.
    $secondWard = Ward::factory()->forLga($lga)->create();
    Facility::factory()->forWard($secondWard)->create();

    $owner = User::factory()->programUser()->create();
    Enrolee::factory()->forUser($owner)->create([
        'lga_id' => $lga->id,
        'ward_id' => $ward->id,
        'facility_id' => $facility->id,
    ]);

    $this->get(route('admin.locations.index'))
        ->assertOk()
        ->assertViewHas('lgas', function ($lgas) use ($lga) {
            $row = $lgas->firstWhere('id', $lga->id);

            return $row->wards_count === 2
                && $row->facilities_count === 2
                && $row->enrolees_count === 1;
        });
});

test('choosing an LGA lists its wards, facilities and edit links', function () {
    actingAsAdmin();

    ['lga' => $lga, 'facility' => $facility] = test()->locationChain();

    $this->get(route('admin.locations.index', ['lga_id' => $lga->id]))
        ->assertOk()
        ->assertSee('Auyakayi')
        ->assertSee($facility->name)
        ->assertSee(route('admin.locations.facilities.edit', $facility), false);
});

test('an administrator can open the facility edit form', function () {
    actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $this->get(route('admin.locations.index'))
        ->assertOk()
        ->assertSee('Add a facility');

    $this->get(route('admin.locations.facilities.edit', $facility))
        ->assertOk()
        ->assertSee($facility->name)
        ->assertSee($ward->name)
        ->assertSee($lga->name)
        ->assertSee(route('admin.locations.facilities.update', $facility), false);
});

test('the edit form warns that a facility in use cannot be moved', function () {
    $owner = User::factory()->programUser()->create();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    Enrolee::factory()->forUser($owner)->create([
        'lga_id' => $lga->id,
        'ward_id' => $ward->id,
        'facility_id' => $facility->id,
    ]);

    actingAsAdmin();

    $this->get(route('admin.locations.facilities.edit', $facility))
        ->assertOk()
        ->assertSee('cannot be moved to another ward');
});

test('an administrator can add a facility to an existing ward', function () {
    $admin = actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward] = test()->locationChain();

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $ward))
        ->assertRedirect(route('admin.locations.index', ['lga_id' => $lga->id]))
        ->assertSessionHas('status');

    $facility = Facility::where('ward_id', $ward->id)
        ->where('name', 'Sabon Gari Health Post')
        ->firstOrFail();

    expect($facility->ward->lga_id)->toBe($lga->id)
        ->and($facility->ward->name)->toBe($ward->name);

    $log = AuditLog::where('action', 'facility_created')->firstOrFail();

    expect($log->actor_id)->toBe($admin->id)
        ->and($log->subject_type)->toBe(Facility::class)
        ->and($log->subject_id)->toBe($facility->id)
        ->and($log->new_values['ward_id'])->toBe($ward->id)
        ->and($log->new_values['lga_id'])->toBe($lga->id);
});

test('a facility name may only be used once within the same ward', function () {
    actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $ward, [
        'name' => $facility->name,
    ]))->assertSessionHasErrors('name');

    expect(Facility::where('ward_id', $ward->id)->count())->toBe(1);

    // The same name in a different ward is legitimate, not a duplicate.
    $otherWard = Ward::factory()->forLga($lga)->create();

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $otherWard, [
        'name' => $facility->name,
    ]))->assertRedirect();

    expect(Facility::where('name', $facility->name)->count())->toBe(2);
});

test('a facility name is required and has a reasonable maximum length', function () {
    actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward] = test()->locationChain();

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $ward, [
        'name' => '   ',
    ]))->assertSessionHasErrors('name');

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $ward, [
        'name' => str_repeat('A', 201),
    ]))->assertSessionHasErrors('name');

    expect(Facility::count())->toBe(1);
});

test('a facility cannot be filed under a ward from a different LGA', function () {
    actingAsAdmin();

    ['lga' => $lga] = test()->locationChain();

    $otherLga = Lga::factory()->create(['name' => 'Kano']);
    $otherWard = Ward::factory()->forLga($otherLga)->create(['name' => 'Bompai']);

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $otherWard))
        ->assertSessionHasErrors('ward_id');

    expect(Facility::where('name', 'Sabon Gari Health Post')->exists())->toBeFalse();
});

test('a newly added facility appears in the registration cascade', function () {
    actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward] = test()->locationChain();

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $ward))
        ->assertRedirect();

    $this->getJson(route('api.facilities', $ward))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Sabon Gari Health Post']);
});

test('the cascade returns only wards and facilities belonging to the chosen parent', function () {
    actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $otherLga = Lga::factory()->create(['name' => 'Kano']);
    $otherWard = Ward::factory()->forLga($otherLga)->create(['name' => 'Bompai']);
    $otherFacility = Facility::factory()->forWard($otherWard)->create();

    $this->getJson(route('api.wards', $lga))
        ->assertOk()
        ->assertJsonFragment(['name' => $ward->name])
        ->assertJsonMissing(['name' => $otherWard->name]);

    $this->getJson(route('api.facilities', $ward))
        ->assertOk()
        ->assertJsonFragment(['name' => $facility->name])
        ->assertJsonMissing(['name' => $otherFacility->name]);
});

test('only an administrator can add, edit or move a facility', function () {
    actingAsProgramUser();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $ward))
        ->assertForbidden();

    $this->get(route('admin.locations.facilities.edit', $facility))->assertForbidden();

    $this->put(route('admin.locations.facilities.update', $facility), facilityManagementPayload($lga, $ward, [
        'name' => 'Changed by a non-admin',
    ]))->assertForbidden();

    expect(Facility::count())->toBe(1)
        ->and($facility->refresh()->name)->toBe('Auyakayi BHC');
});

test('a reviewer cannot add, edit or move a facility either', function () {
    actingAsReviewer();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $this->post(route('admin.locations.facilities.store'), facilityManagementPayload($lga, $ward))
        ->assertForbidden();

    $this->get(route('admin.locations.facilities.edit', $facility))->assertForbidden();

    $this->put(route('admin.locations.facilities.update', $facility), facilityManagementPayload($lga, $ward))
        ->assertForbidden();
});

test('renaming a facility keeps every existing registration pointing at it', function () {
    $owner = User::factory()->programUser()->create();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $enrolee = Enrolee::factory()->forUser($owner)->create([
        'lga_id' => $lga->id,
        'ward_id' => $ward->id,
        'facility_id' => $facility->id,
    ]);

    actingAsAdmin();

    $this->put(route('admin.locations.facilities.update', $facility), facilityManagementPayload($lga, $ward, [
        'name' => 'Auyakayi Model Health Centre',
    ]))->assertRedirect();

    expect($facility->refresh()->name)->toBe('Auyakayi Model Health Centre')
        ->and($enrolee->refresh()->facility_id)->toBe($facility->id)
        ->and($enrolee->ward_id)->toBe($ward->id)
        ->and($enrolee->lga_id)->toBe($lga->id);

    expect(AuditLog::where('action', 'facility_updated')
        ->where('subject_id', $facility->id)
        ->where('actor_id', auth()->id())
        ->exists())->toBeTrue();
});

test('a facility used by a registration cannot be moved to another ward', function () {
    $owner = User::factory()->programUser()->create();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $enrolee = Enrolee::factory()->forUser($owner)->create([
        'lga_id' => $lga->id,
        'ward_id' => $ward->id,
        'facility_id' => $facility->id,
    ]);

    $otherWard = Ward::factory()->forLga($lga)->create(['name' => 'Auyo']);

    actingAsAdmin();

    $this->put(route('admin.locations.facilities.update', $facility), facilityManagementPayload($lga, $otherWard))
        ->assertSessionHasErrors('ward_id');

    expect($facility->refresh()->ward_id)->toBe($ward->id)
        ->and($enrolee->refresh()->ward_id)->toBe($ward->id)
        ->and(AuditLog::where('action', 'facility_moved')->exists())->toBeFalse();
});

test('a soft-deleted registration still protects the facility from being moved', function () {
    $owner = User::factory()->programUser()->create();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $enrolee = Enrolee::factory()->forUser($owner)->create([
        'lga_id' => $lga->id,
        'ward_id' => $ward->id,
        'facility_id' => $facility->id,
    ]);
    $enrolee->delete();

    $otherWard = Ward::factory()->forLga($lga)->create(['name' => 'Auyo']);

    actingAsAdmin();

    $this->put(route('admin.locations.facilities.update', $facility), facilityManagementPayload($lga, $otherWard))
        ->assertSessionHasErrors('ward_id');

    expect($facility->refresh()->ward_id)->toBe($ward->id);
});

test('an unused facility can be moved to another ward and the move is audited', function () {
    $admin = actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    $otherLga = Lga::factory()->create(['name' => 'Kano']);
    $otherWard = Ward::factory()->forLga($otherLga)->create(['name' => 'Bompai']);

    $this->put(route('admin.locations.facilities.update', $facility), facilityManagementPayload($otherLga, $otherWard, [
        'name' => 'Bompai Health Post',
    ]))->assertRedirect(route('admin.locations.index', ['lga_id' => $otherLga->id]));

    $facility->refresh();

    expect($facility->ward_id)->toBe($otherWard->id)
        ->and($facility->name)->toBe('Bompai Health Post')
        ->and($facility->ward->lga_id)->toBe($otherLga->id);

    $log = AuditLog::where('action', 'facility_moved')->firstOrFail();

    expect($log->actor_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($facility->id)
        ->and($log->old_values['ward_id'])->toBe($ward->id)
        ->and($log->new_values['ward_id'])->toBe($otherWard->id);
});

test('saving a facility without changing its ward is not treated as a move', function () {
    actingAsAdmin();

    ['lga' => $lga, 'ward' => $ward, 'facility' => $facility] = test()->locationChain();

    // Keeping the same name must not collide with the row's own unique key.
    $this->put(route('admin.locations.facilities.update', $facility), facilityManagementPayload($lga, $ward, [
        'name' => $facility->name,
    ]))->assertRedirect();

    expect(AuditLog::where('action', 'facility_updated')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'facility_moved')->exists())->toBeFalse();
});
