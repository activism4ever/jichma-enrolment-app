<?php

namespace App\Services;

use App\Enums\EnroleeStatus;
use App\Models\Enrolee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The single definition of "which enrolees match these filters", shared by the
 * admin list, the summary counts and all three export formats.
 *
 * Having one object mean one set of rules is what stops an export from
 * silently disagreeing with the screen the admin looked at before exporting.
 */
final class EnroleeFilters
{
    /**
     * The bounds a bare calendar day is turned into.
     *
     * The date fields carry a day, not an instant, so "to = 2 Oct" has to mean
     * everything from 00:00:00.000 on the 2nd up to and including 23:59:59 on
     * it. Written as explicit timestamps rather than whereDate() so the value
     * is plainly what it looks like, and so a row saved at 23:59:59 on the end
     * day is not quietly dropped on either the export or the list.
     */
    private const DAY_START = '00:00:00';
    private const DAY_END = '23:59:59';

    public function __construct(
        public readonly ?int $programId = null,
        public readonly ?string $status = null,
        public readonly ?int $lgaId = null,
        public readonly ?int $wardId = null,
        public readonly ?int $facilityId = null,
        public readonly ?string $search = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?int $createdBy = null,
    ) {}

    /**
     * Build from a request, ignoring keys the requester did not actually set.
     *
     * `$allowOriginFilters` gates the two filters that identify who a record
     * belongs to: program and creator. Only an administrator may use them.
     * For anyone else they are dropped rather than merely hidden, because a
     * crafted query string of `created_by=<someone else>` would otherwise
     * narrow their own results to nothing (and, on a reviewer's scope, could
     * in principle be used to probe for the existence of records).
     */
    public static function fromRequest(Request $request, bool $allowOriginFilters = true): self
    {
        return self::fromArray($request->all(), $allowOriginFilters);
    }

    /**
     * The same parsing, from a plain array.
     *
     * Needed because the export screen has to count against the filters the
     * administrator currently has on the form, and those arrive as submitted
     * input restored from the session rather than in the query string of the
     * page being rendered. Going through the parser rather than reading the
     * array directly keeps the two in step.
     */
    public static function fromArray(array $input, bool $allowOriginFilters = true): self
    {
        return new self(
            programId: $allowOriginFilters ? self::intOrNull($input['program_id'] ?? null) : null,
            status: self::statusOrNull($input['status'] ?? null),
            lgaId: self::intOrNull($input['lga_id'] ?? null),
            wardId: self::intOrNull($input['ward_id'] ?? null),
            facilityId: self::intOrNull($input['facility_id'] ?? null),
            search: self::clean($input['search'] ?? null),
            from: self::dateOrNull($input['from'] ?? null),
            to: self::dateOrNull($input['to'] ?? null),
            createdBy: $allowOriginFilters ? self::intOrNull($input['created_by'] ?? null) : null,
        );
    }

    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->programId, fn (Builder $q, $v) => $q->where('program_id', $v))
            ->when($this->status, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($this->lgaId, fn (Builder $q, $v) => $q->where('lga_id', $v))
            ->when($this->wardId, fn (Builder $q, $v) => $q->where('ward_id', $v))
            ->when($this->facilityId, fn (Builder $q, $v) => $q->where('facility_id', $v))
            ->when($this->createdBy, fn (Builder $q, $v) => $q->where('created_by', $v))
            ->when($this->from, fn (Builder $q, $v) => $q->where('created_at', '>=', $v.' '.self::DAY_START))
            ->when($this->to, fn (Builder $q, $v) => $q->where('created_at', '<=', $v.' '.self::DAY_END))
            ->when($this->search, function (Builder $q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $q->where(function (Builder $inner) use ($like) {
                    $inner->where('surname', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('nin', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        // Also match the display name a user would search by,
                        // which no single column contains.
                        ->orWhereRaw(
                            "CONCAT_WS(' ', surname, first_name, middle_name) LIKE ?",
                            [$like]
                        );
                });
            });
    }

    /**
     * Base query for a user, already narrowed to what they may see.
     */
    public function queryFor(\App\Models\User $user): Builder
    {
        return $this->apply(Enrolee::query()->visibleTo($user));
    }

    public function toArray(): array
    {
        return array_filter([
            'program_id' => $this->programId,
            'status' => $this->status,
            'lga_id' => $this->lgaId,
            'ward_id' => $this->wardId,
            'facility_id' => $this->facilityId,
            'search' => $this->search,
            'from' => $this->from,
            'to' => $this->to,
            'created_by' => $this->createdBy,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Whether anything at all is narrowing the result set. */
    public function isActive(): bool
    {
        return $this->toArray() !== [];
    }

    /**
     * A copy with one filter dropped.
     *
     * Used by the removable filter chips: dropping "Status: Submitted" must
     * leave the program and the date range in place, or removing a chip would
     * quietly widen the query far more than the person clicking asked for.
     */
    public function without(string $key): self
    {
        $remaining = $this->toArray();

        unset($remaining[$key]);

        return self::fromArray($remaining);
    }

    private static function clean($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    private static function intOrNull($value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function dateOrNull($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private static function statusOrNull($value): ?string
    {
        return in_array($value, EnroleeStatus::values(), true) ? $value : null;
    }
}