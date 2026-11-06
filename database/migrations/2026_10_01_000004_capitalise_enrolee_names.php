<?php

use App\Services\NameFormatter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Capitalise the name columns on records registered before the rule existed.
 *
 * Names are capitalised on save by EnroleeService, but the rows already in the
 * table were typed by hand and carry whatever the keyboard was doing at the
 * time: "bello", "BELLO", " Bello ". This walks them once so the register is
 * consistent from the top, rather than fixing each record as somebody happens
 * to re-save it — which would leave the same person listed two or three ways
 * for as long as the register has been running.
 *
 * Chunked by id rather than loaded whole: the table is expected to grow by
 * thousands, and a bulk select of every name on shared hosting is a memory
 * spike that fails the migration halfway.
 *
 * Only the three name columns are touched. Everything else in the row stays as
 * it is, and the enrolees themselves are not written at all — the update goes
 * through the query builder, so no observer, audit entry or cast can turn a
 * data cleanup into an apparent edit of an attested record.
 *
 * App code is referenced here (NameFormatter) on purpose: a second, copied
 * implementation of the same rule in this file is the classic way for a
 * backfill and the ongoing behaviour to drift apart, with old and new rows
 * then disagreeing. If that class is ever removed, the fix is a migration that
 * only re-capitalises — see down() for why this one cannot be reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $changed = 0;

        // Chunked, and only over rows that could still need work: a name that
        // is already capitalised is skipped by the comparison below, so the
        // query only has to find candidates.
        DB::table('enrolees')
            ->orderBy('id')
            ->chunkById(500, function ($enrolees) use (&$changed) {
                foreach ($enrolees as $enrolee) {
                    $update = [];

                    foreach (['surname', 'first_name', 'middle_name'] as $column) {
                        $current = $enrolee->{$column};

                        if (trim($current) === '') {
                            // Nothing to capitalise. A blank value is left as it
                            // is: turning whitespace or '' into NULL would be a
                            // change of data, not of spelling, and the row may be
                            // a draft the user is still filling in.
                            continue;
                        }

                        $formatted = NameFormatter::title($current);

                        if ($formatted !== null && $formatted !== $current) {
                            $update[$column] = $formatted;
                        }
                    }

                    if ($update === []) {
                        continue;
                    }

                    DB::table('enrolees')
                        ->where('id', $enrolee->id)
                        ->update($update);

                    $changed++;
                }
            });

        // Said plainly, because a migration that reports nothing leaves the
        // operator unable to tell "ran and changed nothing" from "silently
        // failed on half the table".
        $this->report('Capitalised names on '.$changed.' enrolee record(s).');
    }

    /**
     * Nothing to undo.
     *
     * The original capitalisation cannot be recovered from the row: "bello",
     * "BELLO" and " bello " all normalise to "Bello", so the information needed
     * to reverse this has been overwritten. Inventing a "revert" here would run
     * every name through mb_strtolower and destroy the mixed-case names that
     * were already correct, such as O'Laoye or Van Der Berg.
     *
     * A backup taken before the deployment is the only way back, which is worth
     * stating rather than leaving as a surprise.
     */
    public function down(): void
    {
        $this->report('Nothing to reverse: the previous capitalisation is not stored.');
    }

    /**
     * Record what the migration did, durably.
     *
     * Not through Artisan::output(): the migrate command draws each migration as
     * a task line it then rewrites, so anything written to that stream in the
     * middle of a run is overwritten and never seen. The log is what an operator
     * can still read a month later, which is the point of counting.
     */
    private function report(string $line): void
    {
        Log::info('enrolee name capitalisation: '.$line);
    }
};