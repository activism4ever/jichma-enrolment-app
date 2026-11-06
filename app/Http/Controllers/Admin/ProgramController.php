<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\UpdateProgramRequest;
use App\Models\Program;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Administrator-only programme management.
 *
 * Rules enforced here beyond ordinary CRUD:
 *
 *   - The code is immutable: it is set once on create and never written again.
 *   - is_active and is_sensitive are outside the model's $fillable, so they are
 *     applied explicitly and only through this controller.
 *   - A programme is deactivated, never deleted: users, enrolees and the audit
 *     trail all reference programmes, so the row has to survive.
 *   - Every change — creation, rename, a sensitivity change and a status change
 *     — is written to the audit trail, because the sensitivity flag decides how
 *     a programme's personal data is handled.
 */
class ProgramController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Program::class);

        // Active programmes first, then alphabetical. Counts are the reason a
        // programme cannot simply be deleted: they show what hangs off it.
        $programs = Program::query()
            ->withCount(['enrolees', 'users'])
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get();

        return view('admin.programs.index', [
            'programs' => $programs,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Program::class);

        return view('admin.programs.create', [
            'program' => new Program,
        ]);
    }

    public function store(StoreProgramRequest $request): RedirectResponse
    {
        // Explicit assignment rather than create($data): is_active and
        // is_sensitive are outside $fillable by design.
        $program = new Program;
        $program->code = $request->string('code')->toString();
        $program->name = $request->string('name')->toString();
        $program->description = $this->descriptionFrom($request);
        $program->is_active = true;
        $program->is_sensitive = $request->boolean('is_sensitive');
        $program->save();

        $this->audit->log(
            'program_created',
            "Created programme {$program->label()}",
            $program,
            [],
            [
                'code' => $program->code,
                'name' => $program->name,
                'is_active' => $program->is_active,
                'is_sensitive' => $program->is_sensitive,
            ],
            ['program_id' => $program->id],
        );

        return redirect()->route('admin.programs.index')
            ->with('status', 'Programme created.');
    }

    public function edit(Program $program): View
    {
        $this->authorize('update', $program);

        return view('admin.programs.edit', [
            'program' => $program,
        ]);
    }

    public function update(UpdateProgramRequest $request, Program $program): RedirectResponse
    {
        $before = [
            'name' => $program->name,
            'description' => $program->description,
            'is_sensitive' => $program->is_sensitive,
        ];

        // code is never written here: it is immutable after creation.
        $program->name = $request->string('name')->toString();
        $program->description = $this->descriptionFrom($request);
        $program->is_sensitive = $request->boolean('is_sensitive');
        $program->save();

        $after = [
            'name' => $program->name,
            'description' => $program->description,
            'is_sensitive' => $program->is_sensitive,
        ];

        $changed = AuditLogger::diff($before, $after);

        if ($changed !== []) {
            $this->audit->log(
                'program_updated',
                "Updated programme {$program->label()}",
                $program,
                $before,
                $after,
                ['program_id' => $program->id],
            );
        }

        return redirect()->route('admin.programs.index')
            ->with('status', 'Programme updated.');
    }

    public function destroy(Request $request, Program $program): RedirectResponse
    {
        $this->authorize('deactivate', $program);

        // Deactivation only. The row, its enrolees, its account assignments and
        // its audit history all stay exactly as they are.
        $program->forceFill(['is_active' => false])->save();

        $this->audit->log(
            'program_deactivated',
            "Deactivated programme {$program->label()}",
            $program,
            ['is_active' => true],
            ['is_active' => false],
            ['program_id' => $program->id],
        );

        return redirect()->route('admin.programs.index')
            ->with('status', 'Programme deactivated. Existing records and accounts are unchanged.');
    }

    public function activate(Request $request, Program $program): RedirectResponse
    {
        $this->authorize('activate', $program);

        $program->forceFill(['is_active' => true])->save();

        $this->audit->log(
            'program_activated',
            "Reactivated programme {$program->label()}",
            $program,
            ['is_active' => false],
            ['is_active' => true],
            ['program_id' => $program->id],
        );

        return redirect()->route('admin.programs.index')
            ->with('status', 'Programme reactivated.');
    }

    /** An emptied description is stored as NULL, not ''. */
    private function descriptionFrom(Request $request): ?string
    {
        return $request->filled('description')
            ? $request->string('description')->toString()
            : null;
    }
}
