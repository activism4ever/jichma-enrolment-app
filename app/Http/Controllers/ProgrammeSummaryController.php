<?php

namespace App\Http\Controllers;

use App\Models\Lga;
use App\Models\Program;
use App\Models\User;
use App\Models\Ward;
use App\Services\EnroleeFilters;
use App\Services\EnroleeSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

/**
 * Programme summary: enrolment totals per programme, per LGA and per ward.
 *
 * Analytics and navigation, deliberately not a second enrolee-management
 * workflow. The counts come from EnroleeSummary operating on the same scoped
 * builder the list screen uses, and the only route into records is a filtered
 * link to the existing Enrolees page, so a summary can never show rows the
 * enrolee list would refuse, and every count agrees with the list behind it.
 *
 * Authorisation is enforced here, at the route, not by hiding navigation:
 * an administrator may open any programme's summary, while a Reviewer or
 * Program User is refused (403) for any programme but their own. The program
 * id in the URL is never trusted by itself; the scoped query re-checks it
 * against Enrolee::visibleTo().
 */
class ProgrammeSummaryController extends Controller
{
    public function __construct(
        private readonly EnroleeSummary $summary,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Programme and creator are administrator-only filters, exactly as on
        // the enrolee list: a crafted query string can never narrow anyone
        // else's view of a programme they cannot see.
        $filters = $this->summaryFilters($request, $user->isAdmin());

        $programmes = $this->programmesFor($user, $filters);

        try {
            $totals = $this->summary->programmeTotals($filters->queryFor($user));
        } catch (Throwable) {
            return $this->indexView($user, $filters, null, failed: true);
        }

        $cards = $programmes->map(fn (Program $program) => [
            'program' => $program,
            'total' => $totals[$program->id]['total'] ?? 0,
            'month' => $totals[$program->id]['month'] ?? 0,
            'today' => $totals[$program->id]['today'] ?? 0,
        ]);

        return $this->indexView($user, $filters, $cards);
    }

    public function show(Request $request, Program $program): View
    {
        $this->authorizeProgram($request->user(), $program);

        // The programme is bound by the route, not by query input, so the
        // programme filter is dropped here: giving it any meaning on this page
        // would let an odd query string pretend to change which programme is
        // being summarised.
        $filters = $this->summaryFilters($request, $request->user()->isAdmin(), withProgram: false);

        $scoped = $filters->queryFor($request->user())
            ->where('enrolees.program_id', $program->id);

        try {
            $headline = $this->summary->totalFor($scoped);
            [$breakdown, $breakdownLga] = $this->breakdown($request->user(), $filters, $program);
        } catch (Throwable) {
            return $this->showView($request->user(), $program, $filters, null, null, null, failed: true);
        }

        return $this->showView(
            $request->user(),
            $program,
            $filters,
            $headline,
            $breakdownLga,
            $breakdown,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Support                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * The filters the summary actually offers: date range, LGA, ward and (for
     * an administrator) programme.
     *
     * Read key by key rather than with fromRequest() so that anything else in
     * the query string — a status, a free-text search, a creator — is ignored
     * instead of quietly honoured. This page has no control for those, and a
     * total with an invisible filter is a total nobody can interpret. The
     * programme key is also dropped when $withProgram is false, for the detail
     * page where the programme is bound by the URL.
     */
    private function summaryFilters(Request $request, bool $allowOriginFilters, bool $withProgram = true): EnroleeFilters
    {
        return EnroleeFilters::fromArray([
            'program_id' => $withProgram && $allowOriginFilters ? $request->input('program_id') : null,
            'lga_id' => $request->input('lga_id'),
            'ward_id' => $request->input('ward_id'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ], allowOriginFilters: $allowOriginFilters);
    }

    /**
     * Which programmes the index may summarise.
     *
     * An all-programmes administrator sees every configured programme (zeros
     * included); the programme filter narrows that list. A Reviewer or Program
     * User is bound to their one program and the filter is ignored for them.
     */
    private function programmesFor(User $user, EnroleeFilters $filters): Collection
    {
        $programmes = $this->summary->programmesFor($user);

        if ($user->isAdmin() && $filters->programId !== null) {
            return $programmes->filter(fn (Program $program) => $program->id === $filters->programId);
        }

        return $programmes;
    }

    /**
     * Ward options for the filter form: dependent on the selected LGA, so the
     * list stops after the wards that could possibly belong to the chosen LGA.
     */
    private function wardsFor(?int $lgaId): Collection
    {
        return $lgaId !== null
            ? Ward::query()->where('lga_id', $lgaId)->orderBy('name')->get()
            : Ward::query()->orderBy('name')->get();
    }

    /**
     * The breakdown for the detail page: wards when an LGA is in play
     * (either chosen directly or implied by a chosen ward), otherwise LGAs.
     *
     * The ward table is always a breakdown *of* the LGA, so the ward filter is
     * removed from it — data-cleaning a ward list down to one non-zero row
     * would leave the drill-down unable to show the ward's neighbouring rows.
     *
     * @return array{0: Collection, 1: \App\Models\Lga|null}
     */
    private function breakdown(User $user, EnroleeFilters $filters, Program $program): array
    {
        $ward = $filters->wardId !== null ? Ward::find($filters->wardId) : null;
        $lgaId = $filters->lgaId ?? $ward?->lga_id;

        // Inside one LGA the ward filter has no business narrowing the rows,
        // so the breakdown deliberately counts without it.
        $base = $filters->without('ward_id');

        if ($lgaId !== null) {
            $wardScoped = $base
                ->queryFor($user)
                ->where('enrolees.program_id', $program->id)
                ->where('enrolees.lga_id', $lgaId);

            return [$this->summary->wardBreakdown($wardScoped), Lga::find($lgaId)];
        }

        return [$this->summary->lgaBreakdown($base->queryFor($user)->where('enrolees.program_id', $program->id)), null];
    }

    private function authorizeProgram(User $user, Program $program): void
    {
        if ($user->isAdmin() || $program->id === $user->program_id) {
            return;
        }

        abort(403);
    }

    /**
     * The query-string parameters a filtered link must carry to arrive at the
     * same view of the data. The programme travels as an argument on its own,
     * and only the filters that are actually set are included.
     *
     * @return array<string, int|string>
     */
    private function linkParams(EnroleeFilters $filters, Program $program): array
    {
        return array_merge(
            ['program_id' => $program->id],
            array_filter([
                'from' => $filters->from,
                'to' => $filters->to,
                'lga_id' => $filters->lgaId,
                'ward_id' => $filters->wardId,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * A one-line description of what the visible totals cover. When no filters
     * are active there is nothing to say: the numbers are plain all-time
     * totals. Once a date range or a location is chosen, the page must say so
     * rather than silently mixing an all-time total with filtered figures.
     */
    private function scopeNote(EnroleeFilters $filters): ?string
    {
        if ($filters->from !== null || $filters->to !== null) {
            return "Totals cover the selected date range; 'This month' and 'Today' count enrolments inside that range on their calendar windows.";
        }

        if ($filters->isActive()) {
            return "Totals are all-time within the selected location filters.";
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* View wiring                                                        */
    /* ------------------------------------------------------------------ */

    private function indexView(User $user, EnroleeFilters $filters, ?Collection $cards, bool $failed = false): View
    {
        return view('programme-summary.index', [
            'user' => $user,
            'filters' => $filters,
            'cards' => $cards,
            'lgas' => Lga::orderBy('name')->get(),
            'wards' => $this->wardsFor($filters->lgaId),
            'scopeNote' => $this->scopeNote($filters),
            'dateParams' => array_filter([
                'from' => $filters->from,
                'to' => $filters->to,
                'lga_id' => $filters->lgaId,
                'ward_id' => $filters->wardId,
            ], fn ($value) => $value !== null),
            'failed' => $failed,
        ]);
    }

    private function showView(
        User $user,
        Program $program,
        EnroleeFilters $filters,
        ?array $headline,
        ?Lga $breakdownLga,
        ?Collection $breakdown,
        bool $failed = false,
    ): View {
        $ward = $filters->wardId !== null ? Ward::find($filters->wardId) : null;

        return view('programme-summary.show', [
            'user' => $user,
            'program' => $program,
            'filters' => $filters,
            'headline' => $headline,
            'selectedLga' => $breakdownLga,
            'selected' => $ward,
            'breakdown' => $breakdown,
            'scopeNote' => $this->scopeNote($filters),
            'enroleesParams' => $this->linkParams($filters, $program),
            'dateParams' => array_filter([
                'from' => $filters->from,
                'to' => $filters->to,
            ], fn ($value) => $value !== null),
            'failed' => $failed,
        ]);
    }
}