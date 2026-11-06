<?php

namespace App\Console\Commands;

use App\Models\Export;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Delete the files behind exports nobody can download any more.
 *
 * A ready export's download link stops working once it expires, but the file
 * itself would otherwise sit on disk indefinitely holding passport photographs
 * and NINs. This is the counterpart to that expiry: it removes the artefact and
 * keeps the row, so the audit trail still shows what was produced and when.
 *
 * Intended to run daily from cron:
 *
 *   php artisan exports:prune
 *
 * It also sweeps the staging files of exports that never finished, which no row
 * points at. See sweepStagingFiles() for why those cannot be left to the loop.
 *
 * The window is deliberately longer than the retention period: a file is only
 * removed once its link has been dead for a day, so an export requested late on
 * a quiet evening is not removed before it could plausibly have been collected.
 */
class PruneExports extends Command
{
    protected $signature = 'exports:prune
        {--days=1 : Remove artefacts whose download link expired this many days ago}
        {--dry-run : List what would be removed without touching anything}
        {--include-failed : Also remove files from exports that failed part-way}';

    protected $description = 'Delete the generated files behind expired exports';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        // Keep the row, drop the bytes: the audit trail needs to show that an
        // export was produced, and a deleted row would read as "never happened".
        $query = Export::query()
            ->whereNotNull('path')
            ->where('expires_at', '<', now()->subDays($days));

        if (! $this->option('include-failed')) {
            $query->where('status', 'ready');
        }

        $removed = 0;
        $orphans = 0;
        $failed = 0;

        // Chunked so a long-neglected install does not load every row at once.
        $query->orderBy('id')->chunkById(200, function ($exports) use (&$removed, &$orphans, &$failed, $dryRun) {            foreach ($exports as $export) {
                $disk = Storage::disk($export->disk);

                if (! $disk->exists($export->path)) {
                    // The row is already lying about its file. Clearing the path
                    // is a correction, not a deletion.
                    if (! $dryRun) {
                        $export->forceFill(['path' => null])->save();
                    }

                    $orphans++;

                    continue;
                }

                if (! $dryRun) {
                    try {
                        $disk->delete($export->path);
                    } catch (Throwable $e) {
                        report($e);

                        $this->warn("Could not delete {$export->path}: {$e->getMessage()}");
                        $failed++;

                        continue;
                    }

                    $export->forceFill(['path' => null])->save();
                }

                $removed++;
            }
        });

        $verb = $dryRun ? 'Would remove' : 'Removed';

        [$swept, $sweepFailures] = $this->sweepStagingFiles($dryRun, $days);

        $this->info("{$verb} {$removed} export file(s).");

        if ($swept > 0) {
            $this->line("{$verb} {$swept} partial file(s) from exports that never finished.");
        }

        if ($orphans > 0) {
            $this->line("Cleared {$orphans} row(s) whose file was already gone.");
        }

        $failed += $sweepFailures;

        if ($failed > 0) {
            $this->warn("{$failed} file(s) could not be deleted; check permissions on the private disk.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Remove staging files left behind by an export that never finished.
     *
     * GenerateExport deletes its own .part file when it fails, but a worker killed
     * outright — out of memory, or the host pulling the plug — never reaches that
     * code, and nothing in the database points at what it left: an abandoned
     * export has no path recorded, so the loop above cannot find the file at all.
     * This is the only thing that will ever collect it.
     *
     * The age floor is the job's own 600-second time limit, not the --days
     * window, so that even `exports:prune --days=0` cannot delete the file an
     * export is writing right now. Anything younger than that is either in
     * progress or too new to judge.
     *
     * @return array{0: int, 1: int} [removed, failed]
     */
    private function sweepStagingFiles(bool $dryRun, int $days): array
    {
        $cutoff = min(
            now()->subDays($days)->getTimestamp(),
            now()->subSeconds(600)->getTimestamp(),
        );

        $removed = 0;
        $failed = 0;

        // The disks are read from the exports themselves rather than assumed, so
        // an installation that puts exports somewhere unusual is swept there too.
        foreach (Export::query()->distinct()->pluck('disk') as $name) {
            $disk = Storage::disk($name);

            try {
                $files = $disk->files('exports/tmp');
            } catch (Throwable $e) {
                continue;
            }

            foreach ($files as $file) {
                if (! str_ends_with($file, '.part')) {
                    continue;
                }

                try {
                    if ($disk->lastModified($file) > $cutoff) {
                        continue;
                    }

                    if (! $dryRun) {
                        $disk->delete($file);
                    }
                } catch (Throwable $e) {
                    report($e);

                    $this->warn("Could not delete {$file}: {$e->getMessage()}");

                    $failed++;

                    continue;
                }

                $removed++;
            }
        }

        return [$removed, $failed];
    }
}
