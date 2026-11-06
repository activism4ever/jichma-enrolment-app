<?php

namespace App\Http\Controllers;

use App\Models\Enrolee;
use App\Services\EnroleeFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The signed-in landing screen: how many people are enrolled, how many were
 * enrolled recently, and what to do next.
 *
 * Every number here is produced by asking EnroleeFilters for the same scoped
 * query the list screen uses, rather than counting Enrolee::query() and
 * filtering afterwards. That is the whole point: a Program User's total must be
 * the length of the list they would see at /enrolees, otherwise the dashboard
 * becomes a side channel. A number that disagrees with the list behind it is
 * worse than no number, because it looks authoritative.
 *
 * So the scoping rule lives in exactly one place (Enrolee::visibleTo) and this
 * controller only chooses which counts to ask for.
 *
 * Deliberately three numbers and nothing else. There are no counts by status,
 * per program, or per account on this page: a dashboard that answers a question
 * nobody asked, at the cost of the question everybody does, is worse than a
 * shorter page. Anything about verification, payments or staffing belongs on the
 * screen that owns it, not here.
 */
class DashboardController extends Controller
{
    /** How many of the most recent enrolments to list under the totals. */
    private const RECENT_LIMIT = 8;

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        // An empty filter set: no narrowing, just the access scoping. This is
        // literally the object the list screen builds when no filters are set.
        $scoped = (new EnroleeFilters())->queryFor($user);

        return view('dashboard', [
            'user' => $user,
            'totals' => $this->totals($scoped),
            'recent' => $this->recentEnrollees($scoped),
        ]);
    }

    /**
     * Total enrolees, enrolled this month, enrolled today.
     *
     * One query rather than three, because the same three numbers are asked of
     * the same scoped rows and counting the set three times is three times the
     * work for the same answer.
     *
     * The window is created_at, the moment the record was created, which is what
     * "enrolled this month" has to mean for the number to agree with the list
     * behind it. now()->startOfMonth() and startOfDay() are taken in the
     * application's timezone, the same one the timestamps are stored in, so
     * "today" means today where the office is rather than today in UTC.
     *
     * SUM() over a comparison yields NULL rather than 0 for an empty set, so the
     * cast is what turns "nobody yet" into a zero on the page.
     */
    private function totals(Builder $scoped): array
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
     * The most recently enrolled, newest first.
     *
     * Ordered by created_at and then id, not by updated_at: this is the list of
     * who has been enrolled, so the newest enrolment belongs at the top. The
     * most recently *edited* record is a different thing — after a correction
     * that can be an enrolment from last year — and a dashboard answering "who
     * is new" must not answer "what was touched".
     *
     * The id tiebreaker is not decoration. Records created in the same second
     * are common, in bulk imports and in tests, and without it the order — and
     * therefore the row at the top — would be whatever MySQL returned.
     *
     * created_by is selected for the sake of EnroleePolicy::view, which decides
     * whether the row offers a View link. Left out of the select it reads as
     * null, every record looks unowned, and the links silently disappear.
     */
    private function recentEnrollees(Builder $scoped): Collection
    {
        return (clone $scoped)
            ->with('program:id,code,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'surname', 'first_name', 'middle_name', 'program_id', 'created_by', 'created_at']);
    }
}