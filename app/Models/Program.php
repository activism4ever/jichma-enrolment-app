<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    use HasFactory;

    /**
     * `code` is accepted here only so a programme can be created; the update
     * request does not carry it and the controller never rewrites it, which is
     * how the code stays immutable once a programme exists.
     *
     * `is_active` and `is_sensitive` are deliberately absent: they change which
     * records may be created and what protection a programme's data gets, so
     * they are set explicitly by the administrator controller (and audited)
     * rather than reachable through a stray create($request->all()).
     *
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'description'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_sensitive' => 'boolean',
        ];
    }

    public function enrolees(): HasMany
    {
        return $this->hasMany(Enrolee::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Programmes that may be newly enrolled into, assigned to a new account or
     * chosen on a new export. Historical screens (the enrolee list filter, the
     * programme summary, the audit log) deliberately do NOT use this scope, so
     * a deactivated programme's existing records stay reachable.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Programmes a picker may offer: the active ones, plus — on an edit — the
     * programme a record is already assigned to. Without the second clause an
     * administrator editing an account (or record) whose programme was later
     * deactivated could not change anything without also reassigning it.
     */
    public function scopeAssignable(Builder $query, ?int $keepProgramId = null): Builder
    {
        return $query->where(function (Builder $inner) use ($keepProgramId) {
            $inner->where('is_active', true);

            if ($keepProgramId !== null) {
                $inner->orWhere('id', $keepProgramId);
            }
        });
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * One label per program, used everywhere a program is named.
     *
     * Code and name are separate columns because a program may outgrow its
     * original mnemonic, but the seeded three have name === code. Rendering
     * both unconditionally therefore prints "CEMOG CEMOG" on screen, so the
     * name is only appended when it actually says something the code does not.
     */
    public function label(): string
    {
        return $this->name === $this->code || blank($this->name)
            ? $this->code
            : $this->code.' — '.$this->name;
    }

    /**
     * Whether this programme holds sensitive health data and therefore gets
     * extra masking in list views and audit capture. Backed by the
     * `is_sensitive` column rather than a magic code, so a programme can be
     * renamed, or a new one marked sensitive, without a code change.
     * See README "Data protection".
     */
    public function isSensitive(): bool
    {
        return (bool) $this->is_sensitive;
    }
}
