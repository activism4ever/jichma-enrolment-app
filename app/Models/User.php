<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Deliberately narrow. `must_change_password` and `is_active` are NOT
     * mass-assignable: the starting overlay exposed them, which would let any
     * code path doing `User::create($request->all())` silently grant access.
     * Server-owned columns (role, program_id) are set explicitly by the admin
     * form request instead.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function enrolees(): HasMany
    {
        return $this->hasMany(Enrolee::class, 'created_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isReviewer(): bool
    {
        return $this->role === Role::Reviewer;
    }

    public function isProgramUser(): bool
    {
        return $this->role === Role::ProgramUser;
    }

    /**
     * True when this user's program holds sensitive health data (PLHIV).
     * Admins are not bound to a program, so they are never treated as
     * having a sensitive program of their own.
     */
    public function programIsSensitive(): bool
    {
        return $this->program?->isSensitive() ?? false;
    }

    /**
     * Display label for the navigation bar.
     */
    public function contextLabel(): string
    {
        $program = $this->program?->code ?? 'All programs';

        return "{$this->role->label()} · {$program}";
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Active accounts per role, as [role value => count].
     *
     * One definition so the dashboard's "Active users" card and the Users page
     * cannot disagree. Both screens exist to answer "how many people can
     * actually sign in", and two hand-written counts would drift the first time
     * a filter or an exclusion was changed in one place and not the other.
     *
     * Roles with nobody in them are present with 0 rather than absent, so a
     * missing count cannot be mistaken for a missing role.
     *
     * @return array<string, int>
     */
    public static function activeCountsByRole(): array
    {
        $counts = static::query()
            ->active()
            ->selectRaw('role, COUNT(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        return collect(Role::cases())
            ->mapWithKeys(fn (Role $role) => [$role->value => (int) ($counts[$role->value] ?? 0)])
            ->all();
    }

    /**
     * Admin must have no program; Program User and Reviewer must have exactly
     * one. Enforced here as a backstop so the invariant holds even if a future
     * code path bypasses the form request.
     */
    public function setProgramIdAttribute($value): void
    {
        $role = $this->attributes['role'] ?? ($this->role?->value ?? null);

        $this->attributes['program_id'] = ($role === Role::Admin->value || $role === null)
            ? null
            : $value;
    }
}