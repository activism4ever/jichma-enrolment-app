<?php

namespace App\Services;

use App\Models\Lga;
use App\Models\Program;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The aggregate queries behind the programme summary screens.
 *
 * Every figure here is derived from an already-scoped builder, so the summary
 * can never count rows EnroleeFilters / Enrolee::visibleTo() would not show on
 * the enrolee list behind it. The scoped builder is passed in rather than
 * recreated so that the caller's filters (dates, LGA, ward) travel with the
 * count; this class only decides how to group the result.
 *
 * One query per grouping line rather than one per card: the programme cards,
 * the LGA breakdown and the ward breakdown each ask for three numbers
 * (total, this month, today) across a set of groups, and a grouped SUM() gives
 * all of them in a single pass over the matching rows.
 */
final class EnroleeSummary
{
    /**
     * The programmes this user may see a summary of.
     *
     * An administrator may see every configured programme, even one that has
     * never enrolled anyone, because zero is information. Everyone else is
     * bound to their single programme by the data model.
     */
    public function programmesFor(User $user): Collection
    {
        return $user->isAdmin()
            ? Program::orderBy('code')->get()
            : Program::query()->whereKey($user->program_id)->orderBy('code')->get();
    }

    /**
     * Total, this month and today per programme, keyed by program id.
     *
     * The month and today windows use the same application timezone the
     * dashboard's KPI cards use, and the same created_at definition of
     * "enrolled", so the per-program cards agree with the list they link to.
     *
     * @return Collection<int, array{total: int, month: int, today: int}>
     */
    public function programmeTotals(Builder $scoped): Collection
    {
        return $this->grouped($scoped, 'program_id')
            ->mapWithKeys(fn ($row) => [$row->id => [
                'total' => (int) $row->total,
                'month' => (int) $row->month,
                'today' => (int) $row->today,
            ]]);
    }

    /**
     * Total, this month and today for the whole scoped set at once.
     *
     * The headline figure for a programme's detail page: one query for the
     * three numbers it shows at the top, mirroring the dashboard's KPI query.
     *
     * @return array{total: int, month: int, today: int}
     */
    public function totalFor(Builder $scoped): array
    {
        $row = (clone $scoped)
            ->selectRaw('COUNT(*) AS total, SUM(created_at >= ?) AS month, SUM(created_at >= ?) AS today', [
                now()->startOfMonth(),
                now()->startOfDay(),
            ])
            ->first();

        return [
            'total' => (int) $row->total,
            'month' => (int) $row->month,
            'today' => (int) $row->today,
        ];
    }

    /**
     * Total, this month and today per LGA, as display rows.
     *
     * Records with no LGA recorded form a single trailing row rather than
     * vanishing: a drill-down must add up to the total it came from, or the
     * numbers above it lose their meaning.
     *
     * @return Collection<int, array{id: int|null, name: string|null, total: int, month: int, today: int}>
     */
    public function lgaBreakdown(Builder $scoped): Collection
    {
        return $this->shapeLocationRows(
            $this->grouped($scoped, 'lga_id'),
            Lga::query()->pluck('name', 'id'),
        );
    }

    /**
     * Total, this month and today per ward, as display rows.
     *
     * The caller is expected to have constrained the builder to one LGA; the
     * ward filter itself must NOT be in the builder, or every ward row except
     * the selected one would read zero and the table would stop being a
     * breakdown of the LGA.
     *
     * @return Collection<int, array{id: int|null, name: string|null, total: int, month: int, today: int}>
     */
    public function wardBreakdown(Builder $scoped): Collection
    {
        return $this->shapeLocationRows(
            $this->grouped($scoped, 'ward_id'),
            Ward::query()->pluck('name', 'id'),
        );
    }

    /**
     * Total, this month and today, grouped by one column.
     *
     * @return Collection<int, object{id: int|null, total: int, month: int, today: int}>
     */
    private function grouped(Builder $scoped, string $column): Collection
    {
        $rows = (clone $scoped)
            ->selectRaw('enrolees.'.$column.' AS id, COUNT(*) AS total, SUM(enrolees.created_at >= ?) AS month, SUM(enrolees.created_at >= ?) AS today', [
                now()->startOfMonth(),
                now()->startOfDay(),
            ])
            ->groupBy('enrolees.'.$column)
            ->orderBy('enrolees.'.$column)
            ->get();

        return $rows->map(fn ($row) => (object) [
            'id' => $row->id,
            'total' => (int) $row->total,
            'month' => (int) $row->month,
            'today' => (int) $row->today,
        ]);
    }

    /**
     * Attach location names, sort case-insensitively, and send nameless rows
     * to the end under an "unrecorded" label.
     *
     * @param  Collection<int, object>  $rows
     * @param  Collection<int, string>  $names
     * @return Collection<int, array{id: int|null, name: string|null, total: int, month: int, today: int}>
     */
    private function shapeLocationRows(Collection $rows, Collection $names): Collection
    {
        $entries = $rows->map(fn ($row) => [
            'id' => $row->id,
            'name' => $row->id !== null ? ($names[$row->id] ?? 'Unknown location') : null,
            'total' => $row->total,
            'month' => $row->month,
            'today' => $row->today,
        ]);

        return $entries
            ->sortBy(fn (array $entry) => strtolower($entry['name'] ?? '~'))
            ->values();
    }
}