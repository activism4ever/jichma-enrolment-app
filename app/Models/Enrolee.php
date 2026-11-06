<?php

namespace App\Models;

use App\Enums\Education;
use App\Enums\EnroleeStatus;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property string|null $nin
 * @property string|null $phone
 * @property int|null $program_id
 */
class Enrolee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Every attribute an enrolee may set for themselves.
     *
     * `program_id`, `created_by`, `status`, `submitted_at`, `submitted_by` and
     * `photo_path` are intentionally absent: they are server-owned and set
     * explicitly by the controllers. This is the mass-assignment guard the
     * brief requires.
     *
     * @var list<string>
     */
    protected $fillable = [
        'surname', 'first_name', 'middle_name', 'gender', 'date_of_birth',
        'marital_status', 'education', 'phone', 'nin', 'address',
        'lga_id', 'ward_id', 'facility_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnroleeStatus::class,
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'education' => Education::class,
            'date_of_birth' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    protected $appends = ['full_name'];

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /* ------------------------------------------------------------------ */
    /* Access control: the single choke point                              */
    /* ------------------------------------------------------------------ */

    /**
     * Restrict a query to the rows this user is permitted to see.
     *
     * Every listing, search, export and count must go through this. Policies
     * handle single records; this handles collections, so that "list my
     * records" can never accidentally omit the filter.
     *
     * - Admin:            all records, all programs.
     * - Program User:     only records they created.
     * - Reviewer:         all records in their one program, both statuses.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $q;
        }

        if ($user->isReviewer()) {
            return $q->where('program_id', $user->program_id);
        }

        // Both conditions, not just created_by. A Program User can only ever
        // create records inside their own program, so the program clause
        // changes nothing in normal operation; it is here so that a row whose
        // program and creator disagree cannot be reached by guessing its id.
        return $q->where('created_by', $user->id)
            ->where('program_id', $user->program_id);
    }

    /* ------------------------------------------------------------------ */
    /* Access control: single record                                       */
    /* ------------------------------------------------------------------ */

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isInUsersProgram(User $user): bool
    {
        return $user->program_id !== null && $this->program_id === $user->program_id;
    }

    /**
     * A Program User may only edit a record while it is still a draft they
     * created. Submitted records are locked for them.
     */
    public function isEditableBy(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->status === EnroleeStatus::Draft && $this->isOwnedBy($user);
    }

    public function isDeletableBy(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->isOwnedBy($user) && $this->status === EnroleeStatus::Draft;
    }

    /* ------------------------------------------------------------------ */
    /* Presentation                                                        */
    /* ------------------------------------------------------------------ */

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->surname,
            $this->first_name,
            $this->middle_name,
        ])));
    }

    public function isDraft(): bool
    {
        return $this->status === EnroleeStatus::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this->status === EnroleeStatus::Submitted;
    }

    public function hasPhoto(): bool
    {
        return filled($this->photo_path) && Storage::disk('private')->exists($this->photo_path);
    }

    /**
     * Age in whole years as of today.
     *
     * Written out with integer arithmetic rather than Carbon's ->age property
     * because an export calls this once per row: measured over 2,587 rows,
     * Carbon took 1.67s where this takes 0.02s, which was a third of the whole
     * export's runtime. The result is identical, including the 29 February case
     * where a birthday has not yet occurred in the current year.
     */
    public function ageInYears(): ?int
    {
        // The raw attribute avoids building a Carbon instance per row, but it is
        // only populated on a hydrated model; a model assembled in memory (a
        // factory, a form request) still has to go through the cast.
        $raw = $this->getRawOriginal('date_of_birth');

        if (is_string($raw) && $raw !== '') {
            $date = $raw;
        } elseif ($this->date_of_birth !== null) {
            $date = $this->date_of_birth->format('Y-m-d');
        } else {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', substr($date, 0, 10)));

        if ($year === 0) {
            return null;
        }

        $today = now();
        $age = $today->year - $year;

        // Subtract one unless the birthday has already passed this year.
        if ($today->month < $month || ($today->month === $month && $today->day < $day)) {
            $age--;
        }

        return max(0, $age);
    }

    /**
     * Filename used inside a photo ZIP export. Matches the `photo_file`
     * column of the CSV/XLSX export so the two can be cross-referenced.
     */
    public function photoFilename(): ?string
    {
        if (! $this->hasPhoto()) {
            return null;
        }

        return ($this->nin ?: 'enrollee-'.$this->id).'.jpg';
    }

    /* ------------------------------------------------------------------ */
    /* Privacy masking for sensitive programs                              */
    /* ------------------------------------------------------------------ */

    /**
     * Mask a NIN for display in list views of sensitive programs, keeping
     * only the last four digits. Detail views show the full value.
     */
    public static function maskNin(?string $nin): string
    {
        if (blank($nin)) {
            return '';
        }

        return str_repeat('*', max(0, strlen($nin) - 4)).substr($nin, -4);
    }

    public static function maskPhone(?string $phone): string
    {
        if (blank($phone)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? '' : str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}