<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only view of the audit trail.
 *
 * There is deliberately no create, update or delete here. The trail is only
 * useful as evidence if the application cannot rewrite it, so entries are
 * written by AuditLogger as a side effect of the action they describe and are
 * never edited through the web interface.
 *
 * Administrators see everything. A Program User may also read their own trail,
 * which is why filtering by actor is available but never by omission.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        $logs = $this->filtered($request)
            ->with(['actor', 'program'])
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'actions' => AuditLog::ACTIONS,
            'actors' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'programs' => Program::orderBy('code')->get(),
            'filters' => $request->only(['action', 'actor_id', 'program_id', 'from', 'to', 'q']),
        ]);
    }

    /**
     * Apply the filter bar.
     *
     * A value the administrator supplied but which is not recognised must match
     * nothing. Silently ignoring it would show the whole log, which is the worst
     * possible answer when someone is trying to prove a negative: they would see
     * unrelated entries and conclude the thing they are looking for is missing
     * when in fact the filter never applied.
     */
    private function filtered(Request $request): Builder
    {
        $query = AuditLog::query();

        $action = $request->input('action');

        if (filled($action)) {
            in_array($action, AuditLog::ACTIONS, true)
                ? $query->where('action', $action)
                : $query->whereRaw('1 = 0');
        }

        if (filled($request->input('actor_id'))) {
            $query->where('actor_id', (int) $request->input('actor_id'));
        }

        if (filled($request->input('program_id'))) {
            $query->where('program_id', (int) $request->input('program_id'));
        }

        // Registered on the day itself, so `to` is inclusive of the whole end
        // day rather than stopping at midnight.
        foreach (['from', 'to'] as $bound) {
            $value = $request->input($bound);

            if (! filled($value)) {
                continue;
            }

            $this->isDate($value)
                ? $query->whereDate('created_at', $bound === 'from' ? '>=' : '<=', $value)
                : $query->whereRaw('1 = 0');
        }

        if (filled($request->input('q'))) {
            // Description is free text written by the application, so the search
            // is deliberately narrow: identifiers and action names.
            $needle = str_replace(['%', '_'], ['\%', '\_'], mb_substr(trim($request->input('q')), 0, 100));

            $query->where(function (Builder $inner) use ($needle) {
                $inner->where('action', 'like', "%{$needle}%")
                    ->orWhere('description', 'like', "%{$needle}%");
            });
        }

        return $query;
    }

    private function isDate($value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
