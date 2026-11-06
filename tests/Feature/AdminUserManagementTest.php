<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Account management
|--------------------------------------------------------------------------
|
| Accounts are the way access to personal data is granted, so these tests lean
| hard on the rules that are easy to get wrong: the temporary password, the
| role/program pairing, and the last-administrator guard.
|
*/

test('only an administrator can reach the user list', function () {
    actingAsProgramUser();

    $this->get(route('admin.users.index'))->assertForbidden();
    $this->get(route('admin.users.create'))->assertForbidden();
});

test('a guest is sent to the login page', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('an administrator sees the account list', function () {
    actingAsAdmin();

    $user = User::factory()->programUser()->create(['name' => 'Amina Bello']);

    $this->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Amina Bello');
});

test('the user list can be filtered by role and by name', function () {
    actingAsAdmin();

    User::factory()->reviewer()->create(['name' => 'Reviewer Person']);
    User::factory()->programUser()->create(['name' => 'Program Person']);

    $this->get(route('admin.users.index', ['role' => 'reviewer']))
        ->assertOk()
        ->assertSee('Reviewer Person')
        ->assertDontSee('Program Person');

    $this->get(route('admin.users.index', ['search' => 'Program']))
        ->assertOk()
        ->assertSee('Program Person')
        ->assertDontSee('Reviewer Person');
});

test('a new account is issued a temporary password it must change', function () {
    actingAsAdmin();

    $this->post(route('admin.users.store'), [
        'name' => 'Grace Eze',
        'email' => 'grace@example.test',
        'role' => Role::ProgramUser->value,
        'program_id' => test()->program('CEMOG')->id,
        'password' => 'Temporary1pass',
        'password_confirmation' => 'Temporary1pass',
    ])->assertRedirect();

    $created = User::where('email', 'grace@example.test')->sole();

    // The flag is the whole point: without it the account could be used with a
    // password the administrator chose and transmitted.
    expect($created->must_change_password)->toBeTrue()
        ->and($created->is_active)->toBeTrue()
        ->and($created->program_id)->toBe(test()->program('CEMOG')->id);

    // And the password must not be the one the administrator typed.
    expect(Hash::check('Temporary1pass', $created->password))->toBeTrue();

    expect(AuditLog::where('action', 'user_created')->exists())->toBeTrue();
});

test('an account cannot be created without a program when the role needs one', function () {
    actingAsAdmin();

    $this->post(route('admin.users.store'), [
        'name' => 'No Program',
        'email' => 'noprog@example.test',
        'role' => Role::ProgramUser->value,
        'program_id' => null,
        'password' => 'Temporary1pass',
        'password_confirmation' => 'Temporary1pass',
    ])->assertSessionHasErrors('program_id');

    expect(User::where('email', 'noprogram@example.test')->exists())->toBeFalse();
});

test('an administrator account cannot be given a program', function () {
    actingAsAdmin();

    $this->post(route('admin.users.store'), [
        'name' => 'Second Admin',
        'email' => 'admin2@example.test',
        'role' => Role::Admin->value,
        // A crafted request sends this even though the form hides the field.
        'program_id' => test()->program('CEMOG')->id,
        'password' => 'Temporary1pass',
        'password_confirmation' => 'Temporary1pass',
    ])->assertSessionHasErrors('program_id');

    expect(User::where('email', 'admin2@example.test')->exists())->toBeFalse();
});

test('a duplicate email is refused', function () {
    actingAsAdmin();

    User::factory()->create(['email' => 'taken@example.test']);

    $this->post(route('admin.users.store'), [
        'name' => 'Impostor',
        'email' => 'taken@example.test',
        'role' => Role::ProgramUser->value,
        'program_id' => test()->program('CEMOG')->id,
        'password' => 'Temporary1pass',
        'password_confirmation' => 'Temporary1pass',
    ])->assertSessionHasErrors('email');
});

test('a weak temporary password is refused', function () {
    actingAsAdmin();

    $this->post(route('admin.users.store'), [
        'name' => 'Weak',
        'email' => 'weak@example.test',
        'role' => Role::ProgramUser->value,
        'program_id' => test()->program('CEMOG')->id,
        'password' => 'allletters',
        'password_confirmation' => 'allletters',
    ])->assertSessionHasErrors('password');
});

test('a password cannot be changed through the ordinary edit form', function () {
    $admin = actingAsAdmin();
    $user = User::factory()->programUser()->create();

    // The edit form has no password field; smuggling one in must not work.
    $this->put(route('admin.users.update', $user), [
        'name' => 'Renamed',
        'email' => $user->email,
        'role' => Role::ProgramUser->value,
        'program_id' => $user->program_id,
        'is_active' => '1',
        'password' => 'SneakyChange1',
    ])->assertRedirect();

    expect(Hash::check('SneakyChange1', $user->refresh()->password))->toBeFalse();
});

test('editing an account records what changed', function () {
    actingAsAdmin();

    $user = User::factory()->programUser()->create(['name' => 'Before']);

    $this->put(route('admin.users.update', $user), [
        'name' => 'After',
        'email' => $user->email,
        'role' => Role::ProgramUser->value,
        'program_id' => $user->program_id,
        'is_active' => '1',
    ])->assertRedirect();

    expect($user->refresh()->name)->toBe('After');

    $log = AuditLog::where('action', 'user_updated')->sole();

    expect($log->old_values['name'])->toBe('Before')
        ->and($log->new_values['name'])->toBe('After');
});

test('an unchecked status box really deactivates the account', function () {
    actingAsAdmin();

    $user = User::factory()->programUser()->create();

    // This is the request a browser sends when the checkbox is cleared: the
    // hidden input supplies is_active=0 and the checkbox supplies nothing.
    $this->put(route('admin.users.update', $user), [
        'name' => $user->name,
        'email' => $user->email,
        'role' => Role::ProgramUser->value,
        'program_id' => $user->program_id,
        'is_active' => '0',
    ])->assertRedirect();

    expect($user->refresh()->is_active)->toBeFalse();
});

test('an administrator cannot deactivate their own account', function () {
    $admin = actingAsAdmin();

    $this->delete(route('admin.users.destroy', $admin))->assertForbidden();

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('the last active administrator cannot be demoted or deactivated', function () {
    $admin = actingAsAdmin();

    $this->put(route('admin.users.update', $admin), [
        'name' => $admin->name,
        'email' => $admin->email,
        'role' => Role::ProgramUser->value,
        'program_id' => test()->program('CEMOG')->id,
        'is_active' => '1',
    ])->assertSessionHasErrors('role');

    $this->put(route('admin.users.update', $admin), [
        'name' => $admin->name,
        'email' => $admin->email,
        'role' => Role::Admin->value,
        'program_id' => null,
        'is_active' => '0',
    ])->assertSessionHasErrors('role');

    expect($admin->refresh()->is_active)->toBeTrue()
        ->and($admin->role)->toBe(Role::Admin);
});

test('demoting an administrator is allowed once a second one exists', function () {
    actingAsAdmin();

    $second = User::factory()->admin()->create();

    $this->put(route('admin.users.update', $second), [
        'name' => $second->name,
        'email' => $second->email,
        'role' => Role::ProgramUser->value,
        'program_id' => test()->program('CEMOG')->id,
        'is_active' => '1',
    ])->assertRedirect();

    expect($second->refresh()->role)->toBe(Role::ProgramUser);
});

test('a deactivated account can be reactivated', function () {
    actingAsAdmin();

    $user = User::factory()->programUser()->inactive()->create();

    $this->post(route('admin.users.activate', $user))->assertRedirect();

    expect($user->refresh()->is_active)->toBeTrue();

    expect(AuditLog::where('action', 'user_activated')->exists())->toBeTrue();
});

test('deactivating an account never removes the row', function () {
    actingAsAdmin();

    $user = User::factory()->programUser()->create();

    $this->delete(route('admin.users.destroy', $user))->assertRedirect();

    // The audit trail references user ids, so the row has to survive.
    expect(User::find($user->id))->not->toBeNull()
        ->and($user->refresh()->is_active)->toBeFalse();

    expect(AuditLog::where('action', 'user_deactivated')->exists())->toBeTrue();
});

test('issuing a temporary password forces a change and is audited', function () {
    actingAsAdmin();

    $user = User::factory()->programUser()->create();

    $this->put(route('admin.users.password', $user), [
        'password' => 'FreshPassword1',
        'password_confirmation' => 'FreshPassword1',
    ])->assertRedirect();

    expect(Hash::check('FreshPassword1', $user->refresh()->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue();

    expect(AuditLog::where('action', 'user_password_reset')->exists())->toBeTrue();
});

test('a program user cannot administer anyone, including themselves', function () {
    $user = actingAsProgramUser();
    $other = User::factory()->programUser()->create();

    $this->get(route('admin.users.index'))->assertForbidden();
    $this->get(route('admin.users.show', $other))->assertForbidden();
    $this->get(route('admin.users.edit', $user))->assertForbidden();
    $this->post(route('admin.users.store'), [])->assertForbidden();
    $this->put(route('admin.users.password', $user), [
        'password' => 'Escalation1pass',
        'password_confirmation' => 'Escalation1pass',
    ])->assertForbidden();
});

test('a reviewer cannot administer anyone', function () {
    $reviewer = actingAsReviewer();
    $other = User::factory()->programUser()->create();

    $this->get(route('admin.users.show', $other))->assertForbidden();
    $this->delete(route('admin.users.destroy', $other))->assertForbidden();
});

test('a deactivated administrator cannot sign in', function () {
    $admin = User::factory()->admin()->inactive()->create([
        'email' => 'gone@example.test',
    ]);

    $this->post(route('login'), ['email' => 'gone@example.test', 'password' => 'password'])
        ->assertSessionHasErrors('email');
});
