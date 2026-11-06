<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnroleeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportRequest;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Lga;
use App\Models\Program;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnroleeFilters;
use App\Services\FilterChips;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Administrator export requests.
 *
 * Three decisions shape this controller:
 *
 *   1. Nothing is generated inside the request. A CSV of every enrolee, or a
 *      ZIP of every passport photograph, would hold a request open far past
 *      what PHP and the host will allow, so the work is queued and the
 *      administrator reloads to collect it.
 *
 *   2. Every request and every download is written to the audit trail with the
 *      administrator's identity, the format, the filters, how many records were
 *      in scope and when. That is the accountability the Nigeria Data Protection
 *      Act asks for, and it is why the password re-entry that used to sit on this
 *      form was removed: it added a second place to type a password without
 *      adding a control this controller does not already have.
 *
 *   3. The download is authorised against the exports row, not against a
 *      signed URL. A signed URL would keep working for anyone who obtained it
 *      until it expired; here each request re-checks that the signed-in
 *      administrator is the one who asked for the file, that it is ready, and
 *      that it has not expired. The file itself sits outside the web root.
 */
class ExportController extends Controller
{
    /**
     * How many completed exports the history table shows.
     *
     * Capped rather than paginated: the page already has a paginator for
     * "Your exports", and two paginators on one screen would fight over the
     * same ?page= parameter. Twenty-five is a pageful of rows, and the
     * full record of who exported what is in the audit log anyway.
     */
    private const HISTORY_LIMIT = 25;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Export::class);

        // The cron worker is the normal way a waiting export gets built. These
        // two calls make the page honest without it: a job that has been
        // waiting long enough to prove no worker took it is run here, through
        // the same queue:work code path, and an export whose job has vanished
        // entirely is failed rather than left to say "pending" for ever.
        $this->runOneWaitingExport();
        $this->failLostExports($request->user());

        $exports = Export::query()
            ->where('requested_by', $request->user()->id)
            ->with('requester')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Completed exports, newest first. Every administrator sees every
        // row, not just their own: the table's whole point is answering
        // "who exported how much, when, and of what", and a colleague's
        // export is exactly the sort of question that gets asked. The rows
        // carry no file — the download remains per-requester, authorised
        // against the row as before.
        $history = Export::query()
            ->where('status', 'ready')
            ->with('requester')
            ->latest()
            ->limit(self::HISTORY_LIMIT)
            ->get();

        // A pending or processing export is worth surfacing as soon as the page
        // is opened again, since there is no live progress channel here.
        $busy = $exports->getCollection()->contains(
            fn (Export $e) => in_array($e->status, ['pending', 'processing'], true)
        );

        // Only active programmes are offered for a new export; a deactivated
        // programme's existing exports stay visible in the history below.
        $programs = Program::active()->orderBy('code')->get();
        $lgas = Lga::orderBy('name')->get();

        // Count against the filters currently on the form. They are not always
        // in the query string: after a failed submission the form is redisplayed
        // from old input, so that is consulted too. Without this the screen
        // quotes a total that has nothing to do with the extract about to be
        // queued.
        $filters = EnroleeFilters::fromArray(array_filter(
            array_merge($request->all(), $request->old()),
            fn ($value) => $value !== null && $value !== ''
        ));

        $filtered = $filters->queryFor($request->user());

        return view('admin.exports.index', [
            'exports' => $exports,
            'history' => $history,
            'historyLimit' => self::HISTORY_LIMIT,
            'lastExport' => $history->first(),
            'busy' => $busy,
            'programs' => $programs,
            'lgas' => $lgas,
            'statuses' => EnroleeStatus::cases(),
            'filters' => $filters,
            'filterChips' => FilterChips::for($filters, $programs, $lgas),
            'counts' => [
                'matched' => (clone $filtered)->count(),
                'submitted' => (clone $filtered)->where('status', EnroleeStatus::Submitted->value)->count(),
                'photos' => (clone $filtered)->whereNotNull('photo_path')->count(),
            ],
        ]);
    }

    public function store(ExportRequest $request): RedirectResponse
    {
        $filters = EnroleeFilters::fromRequest($request);
        $kind = $request->string('kind')->toString();

        // Counted here, at request time, against the requester's own visibility,
        // and written into the audit entry. The artefact itself re-derives the
        // same query when the job runs, so a row that changed hands in between is
        // not exported — but the count recorded here is the honest answer to
        // "what did this person believe they were taking?" asked afterwards.
        $matched = $filters->queryFor($request->user())->count();
        $requestedAt = now();

        $export = Export::create([
            'uuid' => Export::newUuid(),
            'requested_by' => $request->user()->id,
            'kind' => $kind,
            'status' => 'pending',
            'disk' => 'private',
            'filters' => $filters->toArray(),
            'total_count' => $matched,
        ]);

        // The query is built inside the job from the stored filter ids, so no
        // Builder and no resolved row set ever enter the queue payload.
        GenerateExport::dispatch($export->id, $filters->toArray());

        // This is a bulk read of the most sensitive data in the system, so the
        // entry carries everything needed to answer "who took what, when, and how
        // much" without depending on the exports table surviving: the
        // administrator, the format, the exact filters as submitted, the number
        // of records in scope and the moment of the request. Identity is also
        // carried in actor_id, and is repeated here so the entry reads correctly
        // on its own in an export of the audit trail.
        $this->audit->log(
            'export_requested',
            sprintf(
                'Requested a %s export of %s enrolee%s',
                strtoupper($kind),
                number_format($matched),
                $matched === 1 ? '' : 's'
            ),
            $export,
            [],
            [
                'kind' => $kind,
                'filters' => $filters->toArray(),
                'record_count' => $matched,
                'requested_by' => $request->user()->id,
                'requested_by_email' => $request->user()->email,
                'requested_at' => $requestedAt->toIso8601String(),
            ],
            ['program_id' => $filters->programId]
        );

        return redirect()->route('admin.exports.index')
            ->with('status', 'Export queued. This page refreshes while it builds; you can download it when it is ready.');
    }

    /**
     * Stream the file, checking authorisation before a single byte is read.
     *
     * Returns a redirect rather than a stream when the export cannot be served:
     * it has expired, or the row says ready while the file is gone. Both are
     * ordinary outcomes an administrator can act on, not server errors.
     */
    public function download(Export $export): StreamedResponse|RedirectResponse
    {
        $this->authorize('download', $export);

        if ($export->hasExpired()) {
            return $this->expiredResponse($export);
        }

        if (! $export->isDownloadable()) {
            abort(404);
        }

        $disk = Storage::disk($export->disk);

        if (! $disk->exists($export->path)) {
            // The row says ready but the artefact is gone: a restored database
            // or a cleared disk. Do not offer a download that will 500.
            $export->forceFill(['status' => 'failed', 'error' => 'The generated file is no longer on disk.'])->save();

            return back()->with('error', 'That export file is no longer available. Request it again.');
        }

        // Each download is its own entry, carrying the same facts as the request
        // it fulfils. A bulk read of NINs and photographs that can be collected
        // repeatedly has to be countable: "who exported" is only half of the
        // question, and re-downloading an artefact is exactly what an audit
        // should be able to show.
        $this->audit->log(
            'export_downloaded',
            sprintf(
                'Downloaded a %s export of %s enrolee%s',
                strtoupper($export->kind),
                number_format((int) $export->row_count),
                (int) $export->row_count === 1 ? '' : 's'
            ),
            $export,
            [],
            [
                'kind' => $export->kind,
                'filters' => $export->filters ?? [],
                'record_count' => (int) $export->row_count,
                'path' => $export->path,
                'size_bytes' => (int) $export->size_bytes,
                'requested_by' => $export->requested_by,
                'downloaded_at' => now()->toIso8601String(),
            ],
            ['program_id' => $export->filters['program_id'] ?? null]
        );

        $name = basename($export->path);
        // Strip the uuid prefix: it stops collisions on disk but means nothing
        // to the person opening the file.
        $name = preg_replace('/^[0-9a-f-]{36}-/', '', $name);

        return $disk->download(
            $export->path,
            $name,
            ['Content-Type' => $this->contentTypeFor($export->kind)]
        );
    }

    public function destroy(Request $request, Export $export): RedirectResponse
    {
        $this->authorize('view', $export);

        // Remove the artefact as well as the row: the file is the reason for the
        // row, and leaving it behind would keep passport photographs on disk
        // after the administrator has discarded the request.
        if ($export->path && Storage::disk($export->disk)->exists($export->path)) {
            Storage::disk($export->disk)->delete($export->path);
        }

        $export->delete();

        return redirect()->route('admin.exports.index')
            ->with('status', 'Export discarded.');
    }

    /**
     * Build one export that has been waiting in the queue, in this request.
     *
     * `queue:work --once` is the same command the cron entry runs — one job
     * through the same worker, honouring the same tries and timeout — so the
     * processing model does not change, only who starts it. The ninety-second
     * wait is what keeps this a fallback rather than a rival: a healthy worker
     * picks a job up within a minute, so by the time this runs the queue is
     * normally empty, and only a host with no worker (or one that fires every
     * ten minutes, as shared hosting often does) has work left to do here.
     *
     * The job's own code decides the outcome as it always has; nothing about
     * the file, the disk or the authorisation changes because the page-load
     * worker started it.
     */
    private function runOneWaitingExport(): void
    {
        $queue = (string) config('exports.queue', 'exports');

        $waiting = DB::table('jobs')
            ->where('queue', $queue)
            ->where('available_at', '<=', time() - 90)
            ->exists();

        if (! $waiting) {
            return;
        }

        try {
            Artisan::call('queue:work', [
                '--queue' => $queue,
                '--once' => true,
            ]);
        } catch (Throwable $e) {
            // The page must still render. The row keeps its status, and the
            // cron worker will collect the job when it next runs.
            Log::warning('Could not run a waiting export from the export page.', [
                'queue' => $queue,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fail an export whose queued job no longer exists.
     *
     * A pending row with a live job is simply waiting. A pending row whose
     * job is gone — a cleared jobs table, a worker killed before its first
     * attempt — will never change on its own, and an administrator watching
     * "Processed 0 of 1" has no way to recover. The five minutes on the
     * clock only clear the moment between the row being written and the job
     * being queued; the queue itself is what is actually consulted.
     */
    private function failLostExports(User $user): void
    {
        $stale = Export::query()
            ->where('requested_by', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        $queued = $this->queuedExportIds();

        foreach ($stale as $export) {
            if (in_array($export->id, $queued, true)) {
                continue;
            }

            $export->forceFill([
                'status' => 'failed',
                'error' => 'The export was interrupted before it finished. Please request it again.',
            ])->save();

            Log::warning('Export failed: its queued job is gone.', [
                'export_id' => $export->id,
            ]);
        }
    }

    /**
     * The export ids still sitting in the exports queue, decoded from the
     * serialised job payloads. Only called when a stale row exists, so the
     * normal page load never pays for it.
     *
     * @return array<int, int>
     */
    private function queuedExportIds(): array
    {
        $ids = [];

        $payloads = DB::table('jobs')
            ->where('queue', (string) config('exports.queue', 'exports'))
            ->pluck('payload');

        foreach ($payloads as $payload) {
            $command = json_decode($payload, true)['data']['command'] ?? '';

            if (preg_match('/s:8:"exportId";i:(\d+);/', (string) $command, $match)) {
                $ids[] = (int) $match[1];
            }
        }

        return $ids;
    }

    private function expiredResponse(Export $export): RedirectResponse
    {
        $this->audit->log(
            'export_download_denied',
            'Tried to download an expired export',
            $export,
            [],
            ['expired' => true],
        );

        return back()->with('error', 'That export has expired. Request it again if you still need it.');
    }

    private function contentTypeFor(string $kind): string
    {
        return match ($kind) {
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'photos' => 'application/zip',
            default => 'application/octet-stream',
        };
    }
}
