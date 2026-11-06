<?php

use App\Models\Export;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| TWO separate cron entries are required, and they are not interchangeable.
| Both are documented in deployment/DEPLOYMENT-GUIDE.md and both belong in
| cPanel's "Cron Jobs" panel:
|
|   1. * * * * * cd /path/to/enrolment && php artisan queue:work --queue=exports --stop-when-empty --max-time=55 >> /dev/null 2>&1
|   2. * * * * * cd /path/to/enrolment && php artisan schedule:run >> /dev/null 2>&1
|
|   (1) drains the exports queue. Without it, exports never finish. The
|   --queue=exports part is not optional: jobs are queued on the named
|   "exports" queue, and a worker left on the default queue never sees them.
|   (2) runs the schedule below, which deletes expired export files.
|
| The queue worker is deliberately NOT scheduled here. queue:work is meant to
| be driven by cron on shared hosting, where there is no supervisor and no
| long-running process: --stop-when-empty makes it process what is there and
| exit, and --max-time caps a run so one wedged job cannot overlap the next
| minute's.
|
| Truehost frequently executes cron only every 10-15 minutes regardless of
| what you ask for. Everything here tolerates that: an export that waits a
| quarter of an hour still finishes, and a prune that runs late leaves the
| files for another day before touching them.
|
*/

// Delete the files behind expired exports. Also clears the path on the row, so
// a download link that has stopped working cannot be served from a stale path.
Schedule::command('exports:prune')->daily()->name('prune-expired-exports');

// Drop the export rows themselves once they are well past their retention
// window. Kept separate from the file prune so the audit trail keeps showing
// what was produced for a week after the bytes are gone.
Schedule::command('model:prune', [
    '--model' => [Export::class],
    '--hours' => 24,
])->daily()->name('prune-expired-export-rows');

// Keep the failed_jobs table from growing without bound on a shared host.
Schedule::command('queue:prune-failed --hours=168')->daily()->name('prune-failed-jobs');
