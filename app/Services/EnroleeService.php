<?php

namespace App\Services;

use App\Enums\EnroleeStatus;
use App\Http\Requests\PhoneNormalizer;
use App\Models\Enrolee;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Creating, updating, submitting and deleting enrolees.
 *
 * Every write goes through here so that the invariants hold regardless of which
 * controller called it:
 *
 *   - program_id, created_by, status and submitted_at are derived from the
 *     authenticated user, never from request input.
 *   - The three name columns are capitalised and whitespace-collapsed, so one
 *     person is stored as one spelling however the keyboard was set.
 *   - A submitted record is locked for its Program User.
 *   - A NIN is globally unique; the race between validation and insert is
 *     caught and reported as the same friendly message.
 *   - The previous photograph is deleted only after the new one is safely
 *     stored, so a failed save cannot destroy the only copy.
 */
class EnroleeService
{
    /** MySQL error code for a unique-index violation. */
    private const SQLSTATE_UNIQUE_VIOLATION = '23000';

    /**
     * The unique index on the NIN, as MySQL names it in a duplicate-key error.
     *
     * Read from the live schema rather than assumed, so a rename of the index
     * cannot quietly turn this into a 500 for the user. Compared case-insensitively
     * because the message is matched as a whole.
     */
    private const NIN_INDEX = 'enrolees_nin_unique';

    public function __construct(private readonly PhotoService $photos) {}

    /**
     * Save a new draft.
     *
     * @param  array<string, mixed>  $data  Validated request input.
     */
    public function createDraft(array $data, User $actor, ?UploadedFile $photo = null): Enrolee
    {
        $photoPath = $photo ? $this->photos->store($photo) : null;

        // For an Admin this comes from validated input; for everyone else it is
        // forced to their own program regardless of what was submitted.
        $requestedProgram = isset($data['program_id']) ? (int) $data['program_id'] : null;
        $programId = $this->programFor($actor, $requestedProgram);

        try {
            // forceFill, not create(): program_id, created_by and status are
            // deliberately outside $fillable, so the ordinary mass-assignment
            // path would silently drop them and the insert would fail on the
            // non-nullable program_id column.
            $enrolee = DB::transaction(function () use ($data, $programId, $actor, $photoPath) {
                $enrolee = new Enrolee;

                $enrolee->forceFill(
                    $this->attributesFrom($data) + [
                        'program_id' => $programId,
                        'created_by' => $actor->id,
                        'status' => EnroleeStatus::Draft->value,
                    ]
                );

                $enrolee->save();

                return $enrolee;
            });

            if ($photoPath) {
                $enrolee->forceFill(['photo_path' => $photoPath])->save();
            }
        } catch (QueryException $e) {
            $this->photos->delete($photoPath);
            $this->rethrowDuplicateNin($e);
        }

        return $enrolee->refresh();
    }

    /**
     * Create a record and submit it in one step.
     *
     * This is the path taken when a user fills the form in completely and
     * presses Submit straight away, rather than saving a draft first and
     * submitting from the record page. The row is inserted with the submitted
     * status in a single transaction, so there is no window in which a half
     * written draft exists for a registration that was meant to be final.
     *
     * Full validation has already run in the form request; what is left here is
     * the same state change submit() performs, plus the photograph check.
     */
    public function createSubmitted(array $data, User $actor, ?UploadedFile $photo = null): Enrolee
    {
        if ($photo === null) {
            throw new HttpException(422, 'A passport photograph is required to submit.');
        }

        $photoPath = $this->photos->store($photo);

        $requestedProgram = isset($data['program_id']) ? (int) $data['program_id'] : null;
        $programId = $this->programFor($actor, $requestedProgram);

        try {
            $enrolee = DB::transaction(function () use ($data, $programId, $actor, $photoPath) {
                $enrolee = new Enrolee;

                $enrolee->forceFill(
                    $this->attributesFrom($data) + [
                        'program_id' => $programId,
                        'created_by' => $actor->id,
                        'status' => EnroleeStatus::Submitted->value,
                        'submitted_at' => now(),
                        'submitted_by' => $actor->id,
                        'photo_path' => $photoPath,
                    ]
                );

                $enrolee->save();

                return $enrolee;
            });
        } catch (QueryException $e) {
            // The photograph is only reachable through this row, so if the row
            // was not written there is nothing to point it at any more.
            $this->photos->delete($photoPath);
            $this->rethrowDuplicateNin($e);
        }

        return $enrolee->refresh();
    }

    /**
     * Update an existing record.
     *
     * Works for a Program User editing their own draft and for an Admin editing
     * any record, including a submitted one (which is audited by the caller).
     */
    public function update(Enrolee $enrolee, array $data, ?UploadedFile $photo = null): Enrolee
    {
        $newPhotoPath = $photo ? $this->photos->store($photo) : null;
        $oldPhotoPath = $enrolee->photo_path;

        try {
            DB::transaction(function () use ($enrolee, $data, $newPhotoPath) {
                $enrolee->fill($this->attributesFrom($data));

                if ($newPhotoPath) {
                    $enrolee->forceFill(['photo_path' => $newPhotoPath]);
                }

                $enrolee->save();
            });
        } catch (QueryException $e) {
            // The new file never became the record's photo, so remove it and
            // leave the existing one in place.
            $this->photos->delete($newPhotoPath);
            $this->rethrowDuplicateNin($e);
        }

        // Only now that the row is committed is the old file redundant.
        if ($newPhotoPath && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath) {
            $this->photos->delete($oldPhotoPath);
        }

        return $enrolee->refresh();
    }

    /**
     * Submit a draft: full validation has already run in the form request, so
     * this only flips the state and stamps the time.
     *
     * A photograph is mandatory. If the record somehow has neither an existing
     * file nor a fresh upload, refuse rather than submitting an incomplete
     * record.
     */
    public function submit(Enrolee $enrolee, array $data, User $actor, ?UploadedFile $photo = null): Enrolee
    {
        $hasPhoto = filled($enrolee->photo_path) || $photo !== null;

        if (! $hasPhoto) {
            throw new HttpException(
                422,
                'A passport photograph is required to submit.'
            );
        }

        $newPhotoPath = $photo ? $this->photos->store($photo) : null;
        $oldPhotoPath = $enrolee->photo_path;

        try {
            DB::transaction(function () use ($enrolee, $data, $actor, $newPhotoPath) {
                $enrolee->fill($this->attributesFrom($data));

                if ($newPhotoPath) {
                    $enrolee->forceFill(['photo_path' => $newPhotoPath]);
                }

                $enrolee->forceFill([
                    'status' => EnroleeStatus::Submitted->value,
                    'submitted_at' => now(),
                    'submitted_by' => $actor->id,
                ])->save();
            });
        } catch (QueryException $e) {
            $this->photos->delete($newPhotoPath);
            $this->rethrowDuplicateNin($e);
        }

        if ($newPhotoPath && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath) {
            $this->photos->delete($oldPhotoPath);
        }

        return $enrolee->refresh();
    }

    /**
     * Soft delete. The row, its audit history and its unique NIN all survive,
     * which is what the Nigeria Data Protection Act's record-keeping principle
     * and the soft-delete decision both require.
     */
    public function delete(Enrolee $enrolee): void
    {
        $enrolee->delete();
    }

    /**
     * The three name columns, which are capitalised rather than stored as typed.
     */
    private const NAME_COLUMNS = ['surname', 'first_name', 'middle_name'];

    /**
     * Normalise validated input into column values.
     *
     * Explicit field list rather than passing $data straight through: the
     * request may legitimately carry a CSRF token or a nested photo array, and
     * only these columns may ever be written.
     *
     * Every write path funnels through here — create, create-and-submit,
     * update and submit — so capitalising names here is what makes the rule
     * hold for all of them, including a record whose value arrives from a
     * re-submitted form rather than from the create screen.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFrom(array $data): array
    {
        $columns = [
            'surname', 'first_name', 'middle_name', 'gender', 'date_of_birth',
            'marital_status', 'education', 'nin', 'address',
            'lga_id', 'ward_id', 'facility_id',
        ];

        $attributes = [];

        foreach ($columns as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $data[$column];
            }
        }

        // Names are capitalised and whitespace-collapsed before they are stored.
        // An emptied name has to come back as NULL, not '': the columns are
        // NOT NULL only on submission, and a draft may leave them blank, and
        // '' would then read as a name consisting of nothing.
        foreach (self::NAME_COLUMNS as $name) {
            if (array_key_exists($name, $attributes)) {
                $attributes[$name] = NameFormatter::title(
                    $attributes[$name] === null ? null : (string) $attributes[$name]
                );
            }
        }

        // Empty strings would violate the numeric foreign keys.
        foreach (['lga_id', 'ward_id', 'facility_id'] as $fk) {
            if (array_key_exists($fk, $attributes) && blank($attributes[$fk])) {
                $attributes[$fk] = null;
            }
        }

        // An emptied NIN must be stored as NULL, not '', because the column is
        // a 11-character unique key and several drafts must be able to leave it
        // blank at the same time.
        if (array_key_exists('nin', $attributes) && blank($attributes['nin'])) {
            $attributes['nin'] = null;
        }

        // Normalise the phone number to the canonical local form.
        if (array_key_exists('phone', $data)) {
            $attributes['phone'] = blank($data['phone'])
                ? null
                : PhoneNormalizer::normalize((string) $data['phone']);
        }

        return $attributes;
    }

    /**
     * A Program User registers into their own program. An Admin has no program,
     * so an admin must state which one they are registering into.
     *
     * The chosen program is re-checked for being active here, from the live
     * table, rather than trusted from the request: validation and this write are
     * separate moments and a program can be deactivated in between, and a
     * deactivated program must not receive new registrations. The same check
     * stops a Program User whose own program has since been deactivated.
     */
    private function programFor(User $actor, ?int $requestedProgramId = null): int
    {
        if ($actor->isAdmin()) {
            if ($requestedProgramId === null) {
                throw new HttpException(422, 'Choose the program this registration belongs to.');
            }

            $programId = $requestedProgramId;
        } elseif ($actor->program_id === null) {
            throw new HttpException(422, 'Your account is not assigned to a program.');
        } else {
            $programId = $actor->program_id;
        }

        if (! Program::query()->whereKey($programId)->where('is_active', true)->exists()) {
            throw new HttpException(422, 'That program is not active, so new registrations cannot be added to it.');
        }

        return $programId;
    }

    /**
     * Convert a unique-constraint failure on the NIN into the same friendly
     * validation error the pre-insert check produces.
     *
     * This closes the race between the uniqueness query and the insert: two
     * requests can both pass validation and both insert, and only the database
     * can arbitrate. Without this the loser would see a 500.
     *
     * The message is matched against the index *name*, because that is what
     * MySQL actually reports: the driver says
     * "Duplicate entry '...' for key 'enrolees.enrolees_nin_unique'". Matching
     * on the column as `enrolees.nin` — the form it takes in other drivers'
     * wording — matches nothing at all here, which would silently turn every
     * race into a 500 and leave this handler dead code.
     *
     * Privacy: the message never reveals who holds the NIN or which program.
     */
    private function rethrowDuplicateNin(QueryException $e): never
    {
        if ($e->getCode() === self::SQLSTATE_UNIQUE_VIOLATION
            && str_contains(strtolower($e->getMessage()), self::NIN_INDEX)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'nin' => 'This NIN is already registered.',
            ]);
        }

        throw $e;
    }

    /** Number of photographs on disk, for the admin storage screen. */
    public function countStoredPhotos(): int
    {
        return count(Storage::disk('private')->files('photos'));
    }
}