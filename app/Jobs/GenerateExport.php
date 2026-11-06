<?php

namespace App\Jobs;

use App\Exceptions\ExportRefused;
use App\Models\Enrolee;
use App\Models\Export;
use App\Models\Program;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnroleeFilters;
use App\Services\ExportService;
use App\Services\PhotoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Build one export artefact in the background.
 *
 * Running this off the request is the point: a whole-program CSV or a photo
 * bundle takes far longer than a web request should hold, and a shared host
 * will not tolerate the memory a synchronous export needs.
 *
 * The file is written to the private disk, never the web root, and the exports
 * row is the only authority for whether it may be downloaded. The row is set to
 * ready (with size and row count) or failed, so the administrator's index page
 * can reload and see the outcome rather than holding a request open.
 */
class GenerateExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt, deliberately.
     *
     * A retry would silently start the whole export again from row zero while
     * the administrator watches a status that says "processing", and a partial
     * file from the first attempt is still on disk. A failed export is better
     * answered by a readable reason on the row than by a job that quietly
     * spends an hour producing the same artefact twice.
     */
    public int $tries = 1;

    /**
     * Long enough for a whole-country export on a shared host, short enough that
     * a runaway job is killed before it starves the worker that serves the site.
     */
    public int $jobTimeout = 600;

    public function __construct(
        public readonly int $exportId,
        /** @var array<string, mixed> */
        public readonly array $filters = [],
        public readonly int $timeout = 600,
    ) {
        $this->onQueue(config('exports.queue', 'exports'));
    }

    public function handle(ExportService $exporter, PhotoService $photos): void
    {
        $export = Export::query()->find($this->exportId);

        // A queued job can outlive its row: an administrator may have deleted an
        // abandoned request, or the job is being retried after the record was
        // replaced. Nothing to do in that case.
        if ($export === null) {
            Log::info('GenerateExport skipped: export row no longer exists', [
                'export_id' => $this->exportId,
            ]);

            return;
        }

        $export->forceFill([
            'status' => 'processing',
            'error' => null,
            'processed_count' => 0,
        ])->save();

        try {
            $requester = User::query()->findOrFail($export->requested_by);

            $filters = $this->filters();

            // Visibility is re-derived from the requester at run time rather
            // than from a query captured at request time: the role and program
            // may have changed in the minutes between queueing and running, and
            // an export must never contain rows the requester could no longer see.
            $query = $filters->apply(Enrolee::query()->visibleTo($requester));

            $program = $filters->programId !== null
                ? Program::query()->find($filters->programId)
                : null;

            $progress = fn (int $processed, int $total) => $this->reportProgress($export, $processed, $total);

            [$path, $rows] = match ($export->kind) {
                'photos' => $this->buildPhotoBundle($export, $query, $photos, $program, $progress),
                'xlsx' => $this->buildSpreadsheet($export, $exporter, $query, 'xlsx', $program, $progress),
                default => $this->buildSpreadsheet($export, $exporter, $query, 'csv', $program, $progress),
            };

            $export->forceFill([
                'status' => 'ready',
                'path' => $path,
                'size_bytes' => Storage::disk($export->disk)->size($path),
                'row_count' => $rows,
                'processed_count' => $rows,
                'expires_at' => now()->addHours((int) config('exports.retain_hours', 24)),
            ])->save();

            Log::info('Export generated', [
                'export_id' => $export->id,
                'kind' => $export->kind,
                'rows' => $rows,
            ]);

            // The completion, recorded in the same trail as the request and
            // the download, and carrying the row count this job actually
            // produced rather than the one believed when it was queued. The
            // requester is passed in as the actor because a worker has no
            // session: an export built for an administrator has to show that
            // administrator in the trail, not an empty actor.
            app(AuditLogger::class)->log(
                'export_completed',
                sprintf(
                    'Completed a %s export of %s enrolee%s',
                    strtoupper($export->kind),
                    number_format($rows),
                    $rows === 1 ? '' : 's'
                ),
                $export,
                [],
                [
                    'kind' => $export->kind,
                    'filters' => $export->filters ?? [],
                    'record_count' => $rows,
                    'size_bytes' => (int) $export->size_bytes,
                    'requested_by' => $export->requested_by,
                    'requested_by_email' => $requester->email,
                    'completed_at' => now()->toIso8601String(),
                ],
                ['program_id' => $export->filters['program_id'] ?? null],
                actor: $requester,
            );
        } catch (Throwable $e) {
            // The row carries a sentence that is safe to show on the exports
            // screen: a refusal crafted for the administrator is kept as
            // written, while anything unexpected gets a fixed generic line
            // with the technical detail left in the log below. The message is
            // truncated because it can contain a value from the data itself.
            $export->forceFill([
                'status' => 'failed',
                'error' => $e instanceof ExportRefused
                    ? Str::limit($e->getMessage(), 500)
                    : 'The export could not be generated. Please try again.',
                'path' => null,
            ])->save();

            // The half-written staging file has to go here. It cannot be left for
            // exports:prune to collect, because a failed export has no path
            // recorded and the prune command works from paths: this file would be
            // unreachable to it, sitting on the disk for good. A partial file the
            // size of the export that failed is not a small thing to leave behind
            // on shared hosting.
            $this->discardTemporaryFile($export);

            Log::error('Export failed', ['export_id' => $export->id, 'exception' => $e]);

            throw $e;
        }
    }

    /**
     * Publish how far the export has got.
     *
     * Called once per page rather than once per row: this is a database write,
     * and doing 5,000 of them to narrate a 5,000-row job would cost more than
     * the export it is describing.
     */
    private function reportProgress(Export $export, int $processed, int $total): void
    {
        $export->forceFill([
            'processed_count' => $processed,
            'total_count' => $total,
        ])->save();
    }

    /**
     * Rebuild the filter object from the stored array.
     *
     * Filters are persisted as plain scalars because this job is serialised
     * into the queue table; holding an object with a Builder inside would put a
     * database connection into the payload.
     */
    private function filters(): EnroleeFilters
    {
        $f = $this->filters;

        return new EnroleeFilters(
            programId: $this->intOrNull($f['program_id'] ?? null),
            status: is_string($f['status'] ?? null) ? $f['status'] : null,
            lgaId: $this->intOrNull($f['lga_id'] ?? null),
            wardId: $this->intOrNull($f['ward_id'] ?? null),
            facilityId: $this->intOrNull($f['facility_id'] ?? null),
            search: is_string($f['search'] ?? null) ? $f['search'] : null,
            from: is_string($f['from'] ?? null) ? $f['from'] : null,
            to: is_string($f['to'] ?? null) ? $f['to'] : null,
            createdBy: $this->intOrNull($f['created_by'] ?? null),
        );
    }

    /**
     * @param  callable(int, int): void|null  $progress
     * @return array{0: string, 1: int} stored path and row count
     */
    private function buildSpreadsheet(
        Export $export,
        ExportService $exporter,
        Builder $query,
        string $extension,
        ?Program $program,
        ?callable $progress = null,
    ): array {
        $disk = Storage::disk($export->disk);
        $name = $this->artifactName($export, $extension, $program);

        // Written under a .part name and moved into place only once the writer
        // has closed cleanly, so an interrupted run cannot leave something that
        // looks like a complete file.
        $temporary = $this->temporaryPath($export);
        $disk->put($temporary, '');

        $absolute = $disk->path($temporary);

        $result = $extension === 'xlsx'
            ? $exporter->toXlsx($query, $absolute, $progress)
            : $exporter->toCsv($query, $absolute, $progress);

        $path = 'exports/'.$export->uuid.'-'.$name;
        $disk->move($temporary, $path);

        return [$path, $result['rows']];
    }

    /**
     * Bundle the matching photographs into one ZIP.
     *
     * The archive is not encrypted: ZipArchive cannot password-protect an
     * archive built entry by entry on every PHP build this app will run on, and
     * a comment claiming a password that is not enforced would be worse than
     * none. The bundle's confidentiality rests instead on it never being
     * web-accessible, on only the requesting administrator being able to
     * download it, and on it being deleted when the row expires.
     *
     * Entries are added with CM_STORE. A JPEG is already deflated, so the
     * default compression method spends CPU to save nothing and makes the
     * archive larger; the same bytes are handed to the archive by path, which
     * keeps a whole program's photographs out of PHP's memory.
     *
     * @return array{0: string, 1: int} stored path and number of photos
     */
    private function buildPhotoBundle(
        Export $export,
        Builder $query,
        PhotoService $photos,
        ?Program $program,
        ?callable $progress = null,
    ): array {
        if (! class_exists(ZipArchive::class)) {
            throw new ExportRefused('The zip extension is required for photo bundles.');
        }

        // Refuse early rather than after building a 200 MB archive that will
        // then be thrown away. Counting is a cheap indexed query.
        $limit = (int) config('exports.max_photo_bundle_rows', 5000);
        $matched = (clone $query)->count();

        if ($matched === 0) {
            throw new ExportRefused('No enrolees matched those filters, so there are no photographs to bundle.');
        }

        if ($limit > 0 && $matched > $limit) {
            throw new ExportRefused(sprintf(
                'That filter matches %s enrolees, above the %s a single photo bundle may contain. Narrow it by program or LGA.',
                number_format($matched),
                number_format($limit)
            ));
        }

        if ($progress !== null) {
            $progress(0, $matched);
        }

        $disk = Storage::disk($export->disk);
        $temporary = $this->temporaryPath($export);

        // The .part file is created before the archive is opened, exactly as the
        // spreadsheet path does. ZipArchive will not create the directory it is
        // asked to write into: without this, the first photo bundle on a fresh
        // install fails to close, and it only appears to work on an installation
        // where some earlier CSV export happened to make exports/tmp/ first.
        $disk->put($temporary, '');

        $zipPath = $disk->path($temporary);

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the photo archive.');
        }

        $count = 0;
        $skipped = 0;
        $processed = 0;

        try {
            // Read in pages: a bundle of a whole program is exactly the case where
            // loading every enrolee into memory at once would fail. Only the four
            // columns the filename and the existence check need are selected.
            $query->select(['id', 'surname', 'nin', 'facility_id', 'photo_path'])
                ->with('facility:id,ward_id,name')
                ->chunkById(200, function ($enrolees) use ($zip, $photos, $export, &$count, &$skipped, &$processed, $matched, $progress) {
                    foreach ($enrolees as $enrolee) {
                        $absolute = $photos->absolutePath($enrolee->photo_path);

                        if ($absolute === null) {
                            // One absent photograph is a data problem to read about,
                            // not a reason to throw away the other 4,999.
                            $skipped++;
                            Log::warning('Export photograph missing; skipped.', [
                                'export_id' => $export->id,
                                'enrolee_id' => $enrolee->id,
                                'photo_path' => $enrolee->photo_path,
                            ]);

                            continue;
                        }

                        $name = $this->photoFilename($enrolee);

                        $zip->addFile($absolute, $name);
                        $zip->setCompressionName($name, ZipArchive::CM_STORE);

                        $count++;
                    }

                    $processed += $enrolees->count();

                    if ($progress !== null) {
                        $progress($processed, $matched);
                    }
                });
        } finally {
            // Closed even when a page throws, or the archive is left open on a
            // handle that outlives this job and the partial file with it.
            $zip->close();
        }

        if ($skipped > 0) {
            Log::warning('Photo bundle completed with photographs missing.', [
                'export_id' => $export->id,
                'bundled' => $count,
                'skipped' => $skipped,
            ]);
        }

        $path = 'exports/'.$export->uuid.'-'.$this->artifactName($export, 'zip', $program);
        $disk->move($temporary, $path);

        return [$path, $count];
    }

    private function temporaryPath(Export $export): string
    {
        return 'exports/tmp/'.$export->uuid.'.part';
    }

    /**
     * Throw away the staging file of an export that failed part-way.
     *
     * Best effort, and deliberately silent about its own failure: if the disk
     * cannot be written to, that is very likely why the export failed in the
     * first place, and the original error is the one worth reporting. A warning
     * naming the path is logged so the leftover is at least traceable.
     */
    private function discardTemporaryFile(Export $export): void
    {
        $temporary = $this->temporaryPath($export);

        try {
            Storage::disk($export->disk)->delete($temporary);
        } catch (Throwable $e) {
            Log::warning('Could not remove the partial export file.', [
                'export_id' => $export->id,
                'path' => $temporary,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The visible filename, without the uuid prefix that keeps stored objects
     * from colliding. That prefix stays in the storage path so the real name
     * never reaches the filesystem.
     */
    private function artifactName(Export $export, string $extension, ?Program $program): string
    {
        // ExportService::filename returns an extension-less stem such as
        // "enrolees-plhiv-csv-20260101-120000", so the extension is appended
        // rather than substituted.
        return (new ExportService)->filename($export->kind, $program?->code).'.'.$extension;
    }

    /**
     * A recognisable, sortable photo name that carries no NIN. Putting the NIN
     * in a filename would leak an identifier into any log line or directory
     * listing that touches the archive.
     */
    private function photoFilename(Enrolee $enrolee): string
    {
        return sprintf(
            '%s-%06d-%s.jpg',
            Str::slug($enrolee->surname) ?: 'record',
            $enrolee->id,
            Str::slug($enrolee->facility?->name ?: 'unknown-facility')
        );
    }

    private function intOrNull($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
