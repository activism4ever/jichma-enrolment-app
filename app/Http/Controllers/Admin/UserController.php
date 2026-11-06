<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Program;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Administrator-only account management.
 *
 * Rules enforced here beyond ordinary CRUD:
 *
 *   - An account is never created with a password the user chose and keeps.
 *     Every account starts on a temporary password and is forced to change it
 *     before reaching any other screen.
 *   - role and program_id are not mass-assignable on the model, so they are
 *     applied explicitly after validation.
 *   - Demoting or deactivating the last active administrator is refused, so the
 *     installation cannot be left with nobody able to administer it.
 *   - Every change to an account is audited, since accounts are the means by
 *     which access to personal data is granted.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('program')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('program_id'), fn ($q) => $q->where('program_id', $request->input('program_id')))
            ->when($request->input('status') === 'active', fn ($q) => $q->active())
            ->when($request->input('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->input('search')).'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::options(),
            'programs' => Program::orderBy('code')->get(),
            'filters' => $request->only('role', 'program_id', 'status', 'search'),
            // Active accounts per role, from the same method the dashboard's
            // "Active users" card reads. These counts ignore the filters above:
            // they describe the workforce, not the current result page.
            'roleCounts' => User::activeCountsByRole(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', [
            'roles' => Role::options(),
            // Only active programmes may take on a new account.
            'programs' => Program::active()->orderBy('code')->get(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = new User;

        // Explicit assignment rather than create($data): role, program_id and
        // must_change_password are outside $fillable by design.
        $user->name = $request->string('name')->toString();
        $user->email = $request->string('email')->toString();
        $user->role = $request->enum('role', Role::class);
        $user->program_id = $request->input('program_id');
        $user->password = $request->string('password')->toString();
        $user->must_change_password = true;
        $user->is_active = true;
        $user->save();

        $this->audit->log(
            'user_created',
            "Created {$user->role->label()} account for {$user->email}",
            $user,
            [],
            [
                'email' => $user->email,
                'role' => $user->role->value,
                'program_id' => $user->program_id,
            ],
            ['program_id' => $user->program_id]
        );

        return redirect()->route('admin.users.show', $user)
            ->with('status', 'Account created. The temporary password must be changed at first sign-in.');
    }

    public function show(User $user): View
    {
        $this->authorize('view', $user);

        $user->load('program');

        return view('admin.users.show', [
            'user' => $user,
            'enroleeCount' => $user->enrolees()->count(),
            'recentAudit' => \App\Models\AuditLog::query()
                ->where('actor_id', $user->id)
                ->latest()
                ->limit(15)
                ->get(),
        ]);
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        $user->load('program');

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => Role::options(),
            // Active programmes, plus the account's current one even if it has
            // since been deactivated, so an unrelated edit is still possible.
            'programs' => Program::assignable($user->program_id)->orderBy('code')->get(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'program_id' => $user->program_id,
            'is_active' => $user->is_active,
        ];

        $user->name = $request->string('name')->toString();
        $user->email = $request->string('email')->toString();
        $user->role = $request->enum('role', Role::class);
        $user->program_id = $request->input('program_id');
        $user->is_active = $request->boolean('is_active');
        $user->save();

        $after = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'program_id' => $user->program_id,
            'is_active' => $user->is_active,
        ];

        $changed = AuditLogger::diff($before, $after);

        if ($changed !== []) {
            $this->audit->log(
                'user_updated',
                "Updated {$user->email}",
                $user,
                $before,
                $after,
                ['program_id' => $user->program_id]
            );
        }

        return redirect()->route('admin.users.show', $user)
            ->with('status', 'Account updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        // Accounts are deactivated, never removed: the audit trail references
        // user ids, and a deleted account would orphan those rows.
        $user->forceFill(['is_active' => false])->save();

        $this->audit->log(
            'user_deactivated',
            "Deactivated {$user->email}",
            $user,
            ['is_active' => true],
            ['is_active' => false],
            ['program_id' => $user->program_id]
        );

        return redirect()->route('admin.users.index')
            ->with('status', 'Account deactivated.');
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('activate', $user);

        $user->forceFill(['is_active' => true])->save();

        $this->audit->log(
            'user_activated',
            "Reactivated {$user->email}",
            $user,
            ['is_active' => false],
            ['is_active' => true],
            ['program_id' => $user->program_id]
        );

        return redirect()->route('admin.users.show', $user)
            ->with('status', 'Account reactivated.');
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $user->forceFill([
            'password' => $request->string('password')->toString(),
            // Force the new holder through the change-password screen, since
            // this password was chosen by an administrator and transmitted in
            // whatever channel they used.
            'must_change_password' => true,
            'password_changed_at' => now(),
        ])->save();

        // Invalidate the target's existing sessions, so the previous password
        // cannot continue to be used on another device. Only possible when the
        // session is stored in the database; a file or cookie driver has no
        // rows to clear, and the login check on is_active still applies.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }

        $this->audit->log(
            'user_password_reset',
            "Issued a temporary password for {$user->email}",
            $user,
            [],
            ['must_change_password' => true],
            ['program_id' => $user->program_id]
        );

        return redirect()->route('admin.users.show', $user)
            ->with('status', 'Temporary password issued. The user must change it at next sign-in.');
    }
}
