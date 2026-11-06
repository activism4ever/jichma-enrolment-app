<?php

namespace App\Http\Controllers;

use App\Enums\Education;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Http\Requests\StoreEnroleeRequest;
use App\Http\Requests\SubmitEnroleeRequest;
use App\Http\Requests\UpdateEnroleeRequest;
use App\Models\Enrolee;
use App\Models\Lga;
use App\Models\Program;
use App\Models\Ward;
use App\Services\AuditLogger;
use App\Services\EnroleeFilters;
use App\Services\EnroleeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Enrolee list, create, edit, submit, delete and detail.
 *
 * Validation and authorisation live in the form requests, so this class only
 * orchestrates. Two invariants are worth noting because they are enforced here
 * rather than in the request:
 *
 *   - program_id, created_by and status come from the authenticated user (or,
 *     for an Admin choosing a program, from validated input). Request input can
 *     never set them.
 *   - Editing a *submitted* record is an administrator action and always
 *     records the before and after values in the audit log.
 */
class EnroleeController extends Controller
{
    public function __construct(
        private readonly EnroleeService $enrolees,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Programme and creator are administrator-only filters. For a Program
        // User or Reviewer they are dropped rather than hidden, so a crafted
        // query string cannot narrow their list to another user's records.
        $filters = EnroleeFilters::fromRequest($request, allowOriginFilters: $user->isAdmin());

        $query = $filters->queryFor($user);

        // Land a Program User on their drafts, which is where they left off.
        if ($user->isProgramUser() && ! $request->filled('status')) {
            $query->where('status', 'draft');
        }

        $enrolees = $query
            ->with(['program', 'creator', 'lga', 'ward', 'facility'])
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();

        // With filters on, the count above the table reads "Showing 24 of
        // 1,248", which needs the scope total beside the filtered one; with
        // an empty page it needs the same unfiltered number to tell "never
        // had a record" from "search matched nothing". A populated page with
        // no filters reuses its own total and pays nothing for either.
        $unfilteredTotal = ($filters->isActive() || $enrolees->total() === 0)
            ? EnroleeFilters::fromArray([])->queryFor($user)->count()
            : $enrolees->total();

        return view('enrolees.index', [
            'enrolees' => $enrolees,
            'filters' => $filters,
            'lgas' => Lga::orderBy('name')->get(),
            'wards' => Ward::orderBy('name')->get(),
            'isAdmin' => $user->isAdmin(),
            'unfilteredTotal' => $unfilteredTotal,

            // Whether this account can see any record at all, with the
            // request's filters taken out of the picture. An empty page
            // cannot answer that itself: zero rows with nothing filtered
            // means an empty account, while zero rows with a search typed
            // means the search matched nothing, and the two empty states
            // say different things to two different people.
            'hasAnyRecords' => $unfilteredTotal > 0,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Enrolee::class);

        return view('enrolees.create', $this->formOptions($request, new Enrolee));
    }

    public function store(StoreEnroleeRequest $request): RedirectResponse
    {
        $enrolee = $this->enrolees->createDraft(
            $request->validated(),
            $request->user(),
            $request->file('photo')
        );

        $this->audit->log(
            'enrolee_created',
            "Draft created for {$enrolee->full_name}",
            $enrolee,
            [],
            ['id' => $enrolee->id, 'status' => $enrolee->status->value]
        );

        return redirect()->route('enrolees.show', $enrolee)
            ->with('status', 'Draft saved. You can return to it and submit when complete.');
    }

    public function show(Request $request, Enrolee $enrolee): View
    {
        $this->authorize('view', $enrolee);

        $enrolee->load(['program', 'creator', 'submitter', 'lga', 'ward', 'facility']);

        return view('enrolees.show', [
            'enrolee' => $enrolee,
            'canEdit' => $request->user()->can('update', $enrolee),
            'canSubmit' => $request->user()->can('submit', $enrolee),
            'canDelete' => $request->user()->can('delete', $enrolee),
        ]);
    }

    public function edit(Request $request, Enrolee $enrolee): View
    {
        $this->authorize('update', $enrolee);

        $enrolee->load(['lga', 'ward', 'facility']);

        return view('enrolees.edit', $this->formOptions($request, $enrolee));
    }

    public function update(UpdateEnroleeRequest $request, Enrolee $enrolee): RedirectResponse
    {
        $wasSubmitted = $enrolee->isSubmitted();
        $before = $this->snapshot($enrolee);

        $enrolee = $this->enrolees->update(
            $enrolee,
            $request->validated(),
            $request->file('photo')
        );

        $this->audit->log(
            'enrolee_updated',
            $wasSubmitted
                ? "Submitted record edited by {$request->user()->name}"
                : "Draft updated for {$enrolee->full_name}",
            $enrolee,
            $before,
            $this->snapshot($enrolee)
        );

        return redirect()->route('enrolees.show', $enrolee)
            ->with('status', 'Changes saved.');
    }

    /**
     * Submit a registration straight from the form, creating it in the same
     * step.
     *
     * There is no record to submit yet, so this is not a redirect back to a
     * failed draft: a user who has finished the form should not have to save
     * first. What happens when the form is *not* finished is handled by
     * keepAsDraft(), which saves what is valid so the photograph is not lost.
     */
    public function submitNew(Request $request): RedirectResponse
    {
        // The resolution is inside the try: the container validates a FormRequest
        // as it is built, so the failure is thrown by app() itself, not by
        // validateResolved().
        try {
            $submission = app(SubmitEnroleeRequest::class);
        } catch (ValidationException $failure) {
            return $this->keepAsDraft($request, null, $failure);
        }

        $enrolee = $this->enrolees->createSubmitted(
            $submission->validated(),
            $request->user(),
            $request->file('photo')
        );

        $this->audit->log(
            'enrolee_submitted',
            "{$enrolee->full_name} submitted for enrolment",
            $enrolee,
            [],
            $this->snapshot($enrolee)
        );

        return redirect()->route('enrolees.show', $enrolee)
            ->with('submitted', 'Registration submitted. This record is now locked.');
    }

    /**
     * Submit an existing draft.
     *
     * The form request is resolved by hand rather than type-hinted so that a
     * failed submission can be answered with a saved draft and a list of what is
     * missing, rather than the plain "redirect back to the form" the framework
     * does by default. Authorisation still runs: validateResolved() throws an
     * AuthorizationException before any rule is checked.
     */
    public function submit(Request $request, Enrolee $enrolee): RedirectResponse
    {
        try {
            $submission = app(SubmitEnroleeRequest::class);
        } catch (ValidationException $failure) {
            return $this->keepAsDraft($request, $enrolee, $failure);
        }

        $before = $this->snapshot($enrolee);

        $enrolee = $this->enrolees->submit(
            $enrolee,
            $submission->validated(),
            $request->user(),
            $request->file('photo')
        );

        $this->audit->log(
            'enrolee_submitted',
            "{$enrolee->full_name} submitted for enrolment",
            $enrolee,
            $before,
            $this->snapshot($enrolee)
        );

        return redirect()->route('enrolees.show', $enrolee)
            ->with('submitted', 'Registration submitted. This record is now locked.');
    }

    /**
     * Keep what the user typed after a failed submission.
     *
     * A submission that fails is not discarded and is not left sitting in a
     * browser form that a refresh would wipe. Whatever passes the looser draft
     * rules is saved first — including the photograph, which is the one thing a
     * browser cannot restore — and the user is sent to the edit page with the
     * full list of what is still missing. The record stays a draft.
     *
     * If the input cannot even be saved as a draft, nothing is written: the
     * original failure is re-thrown so the user lands back on the form they came
     * from with their input intact, which is the only outcome that loses nothing.
     */
    private function keepAsDraft(Request $request, ?Enrolee $enrolee, ValidationException $failure): RedirectResponse
    {
        // UpdateEnroleeRequest for an existing record, StoreEnroleeRequest for a
        // new one; both apply the permissive draft rules and both re-check that
        // the signed-in user may write this record. Resolution validates, so the
        // call itself is what throws.
        try {
            $draft = $enrolee
                ? app(UpdateEnroleeRequest::class)
                : app(StoreEnroleeRequest::class);
        } catch (ValidationException) {
            throw $failure;
        }

        $photo = $request->file('photo');

        if ($enrolee) {
            $enrolee = $this->enrolees->update($enrolee, $draft->validated(), $photo);
        } else {
            $enrolee = $this->enrolees->createDraft($draft->validated(), $request->user(), $photo);

            $this->audit->log(
                'enrolee_created',
                "Draft created for {$enrolee->full_name}",
                $enrolee,
                [],
                ['id' => $enrolee->id, 'status' => $enrolee->status->value]
            );
        }

        return redirect()->route('enrolees.edit', $enrolee)
            ->withErrors($failure->errors())
            ->withInput()
            ->with('status', $enrolee->isDraft()
                ? 'Saved as a draft so nothing is lost. Complete the list below, then submit.'
                : 'Changes saved.');
    }

    public function destroy(Request $request, Enrolee $enrolee): RedirectResponse
    {
        $this->authorize('delete', $enrolee);

        $before = $this->snapshot($enrolee);
        $name = $enrolee->full_name;

        $this->enrolees->delete($enrolee);

        $this->audit->log(
            'enrolee_deleted',
            "Deleted {$name}",
            $enrolee,
            $before,
            []
        );

        return redirect()->route('enrolees.index')
            ->with('status', 'Record deleted.');
    }

    /**
     * Data shared by the create and edit forms.
     *
     * @return array<string, mixed>
     */
    private function formOptions(Request $request, Enrolee $enrolee): array
    {
        return [
            'enrolee' => $enrolee,
            'lgas' => Lga::orderBy('name')->get(),
            'genders' => Gender::options(),
            'maritalStatuses' => MaritalStatus::options(),
            'educations' => Education::options(),
            'isAdmin' => $request->user()->isAdmin(),
            // New registrations may only go into an active programme. On edit
            // the programme is fixed and shown as text, so this list is only
            // ever the create form's picker.
            'programs' => $request->user()->isAdmin()
                ? Program::active()->orderBy('code')->get()
                : collect(),
        ];
    }

    /**
     * Auditable field values, stored as the audit log's old/new columns so an
     * administrator's edit to a submitted record can be reviewed later.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Enrolee $enrolee): array
    {
        return [
            'surname' => $enrolee->surname,
            'first_name' => $enrolee->first_name,
            'middle_name' => $enrolee->middle_name,
            'gender' => $enrolee->gender?->value,
            'date_of_birth' => $enrolee->date_of_birth?->format('Y-m-d'),
            'marital_status' => $enrolee->marital_status?->value,
            'education' => $enrolee->education?->value,
            'phone' => $enrolee->phone,
            'nin' => $enrolee->nin,
            'address' => $enrolee->address,
            'lga_id' => $enrolee->lga_id,
            'ward_id' => $enrolee->ward_id,
            'facility_id' => $enrolee->facility_id,
            'status' => $enrolee->status->value,
            'has_photo' => filled($enrolee->photo_path),
        ];
    }
}
