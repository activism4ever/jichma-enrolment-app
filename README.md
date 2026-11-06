# JICHMA Enrolment Registration

A web application for registering enrolees into JICHMA programs, recording a
passport photograph against each registration, and reporting on what has been
collected. Built with Laravel 11 and MySQL, deployable to Truehost cPanel.

Handles personal data — national identification numbers and passport
photographs — so the design leans on a small number of rules applied
consistently rather than on any single clever feature.

---

## What it does

- **Register enrolees** against a program, with a draft stage so partial work
  is never lost, and a submit stage that locks the record.
- **Photographs** re-encoded, shrunk to 800 px, given a random filename, and
  stored outside the web root.
- **Three roles.** An administrator sees everything; a Program User creates
  records in one program; a Reviewer has read-only access across one program.
- **Exports** as CSV, XLSX or a ZIP of photographs, generated in the background.
- **Location management** — 27 LGAs, 276 wards, 279 facilities, replaceable by
  CSV without breaking existing records.
- **An append-only audit log** of every sign-in, record change, export and
  account change.

---

## Roles and what each can do

| | Admin | Program User | Reviewer |
|---|---|---|---|
| Create / edit records | any program | own program, own records | no |
| Submit a record | yes | own records | no |
| Delete a record | any | own **drafts** only | no |
| View records | all | own | whole program |
| Full NIN / phone | yes | own | yes, in their program |
| Manage accounts | yes | no | no |
| Export | yes | no | no |
| Read the audit log | yes | no | no |

A Program User's list is scoped twice: to records they created **and** in their
program. Reviewers see their whole program but cannot change anything.

Sensitive programs (PLHIV) never show full NIN or phone numbers in list views,
for anyone including administrators.

---

## The rules that shape the code

**Server-owned fields are never taken from a request.** `program_id`,
`created_by`, `status` and the submission timestamps are assigned explicitly in
the service layer. `User::$fillable` is `name`, `email`, `password` — nothing
that could grant access.

**Accounts start on a temporary password.** An administrator issues one and sets
`must_change_password`; the account cannot reach any screen except the change
form until its holder picks their own. The last active administrator cannot be
demoted or deactivated, and no one can deactivate their own account.

**Records are soft-deleted, accounts are deactivated.** The audit trail
references both by id, so deleting either would orphan rows that matter.

**Exports run in a queue and expire.** Generating a whole-country CSV would hold
a request open far past what a shared host allows. The file is written to the
private disk, only the administrator who requested it can download it, the link
stops working after `EXPORT_RETAIN_HOURS`, and `exports:prune` removes the bytes
while keeping the row so the trail still shows what was produced.

**An export says how far it has got.** The row records rows written against the
total, once per 500-row page rather than once per row, and the exports page
refreshes itself every 10 seconds while anything is pending or processing. A
status word on its own cannot be told apart from a job that has wedged, and an
administrator should not have to guess which one they are looking at. The job has
a 600-second ceiling and one attempt: a retry would restart the export from row
zero behind a page still claiming it is processing.

**Queuing an export asks for no password.** Every request and every download is
written to the audit trail with the administrator, the format, the filters, the
number of records and the time, and the job re-derives the query from the
requester's own visibility when it runs. Re-typing a password added a second
place to type one without adding a control the route did not already have.

**The audit log cannot be edited from the web.** There is no route that creates,
updates or deletes an entry, and a test asserts those routes do not exist.

**The dashboard answers three questions and stops.** How many people are
enrolled, how many were enrolled recently, and what to do next — a total, a
calendar-month count, a calendar-day count, the three actions, and the newest
enrolments. There are no counts by status, per program or per account, because a
number on that page that the enrolee list cannot filter into rows is a number
nobody can act on. Each count is produced from the same scoped query the list
screen uses, so the two cannot drift.

**Exports are sanitised for spreadsheets.** A cell starting with `=`, `+`, `-` or
`@` is prefixed with an apostrophe, so a surname like `=HYPERLINK(...)` cannot
execute on the machine of whoever opens the CSV.

**The NIN stays blocked after deletion.** A soft-deleted registration keeps its
NIN reserved, enforced by a real MySQL unique index.

---

## Requirements

- PHP **8.2+** with `pdo_mysql`, `mbstring`, `openssl`, `gd`, `fileinfo`, `zip`
- MySQL **8.0+**
- Composer 2
- Node is **not** required — the frontend is plain HTML, CSS and one JavaScript
  file. There is no build step.

---

## Getting started locally

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create the databases, then set DB_* in .env:
#   enrolment       for development
#   enrolment_test  for the test suite
php artisan migrate --seed
php artisan serve
```

The seeder prints `Locations: 27 LGAs, 276 wards, 279 facilities.` and creates
the administrator from `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

---

## Tests

```bash
php artisan test
# or
./vendor/bin/pest
```

**301 tests, 1131 assertions**, all passing. They run against real MySQL rather
than SQLite, which matters: the duplicate-NIN rule depends on a MySQL unique
index, and a nullable column inside a unique index behaves differently under
SQLite.

| File | Covers |
|---|---|
| `tests/Feature/DashboardTest.php` | the three totals, calendar-day and calendar-month boundaries, scoping per role, and what the page must not contain |
| `tests/Feature/EnroleeWorkflowTest.php` | drafts, validation, duplicate NINs, submission, editing and locking, photographs, audit, role isolation, filters, cascading locations |
| `tests/Feature/AdminUserManagementTest.php` | account creation, temporary passwords, role/program pairing, the last-administrator guard, deactivation, authorisation |
| `tests/Feature/AdminExportTest.php` | queueing, generation of all three formats, progress reporting, re-derived permissions, per-requester download authorisation, expiry, failure reporting |
| `tests/Feature/AdminAuditAndLocationTest.php` | append-only audit, filters, location import and its non-destructive behaviour |
| `tests/Feature/ExportPruningTest.php` | retention, file cleanup, and collecting the staging file of an export that never finished |

---

## The export queue

`QUEUE_CONNECTION=database` (already set in `.env.example`) and the `jobs`,
`job_batches` and `failed_jobs` tables from the default migrations. Confirm both
before expecting an export to appear:

```bash
php artisan migrate --force
php artisan queue:failed      # must be empty, or read what is in it
```

Nothing runs in the background by itself: an export is built by a queue
worker on the named `exports` queue. On a shell:

```bash
php artisan queue:work --queue=exports --stop-when-empty --max-time=55
```

On cPanel that belongs in cron, once a minute, alongside the prune entry — see
[deployment/DEPLOYMENT-GUIDE.md](deployment/DEPLOYMENT-GUIDE.md). `--max-time=55`
is deliberate: the host kills a cron job at 60 seconds, and a worker killed
mid-write resumes on the next run rather than being killed mid-write every time.

The `--queue=exports` part is not optional: jobs are queued on that named
queue, and a worker left listening on the default queue never sees them.

While nobody has configured that worker yet, the exports page helps itself: a
job that has sat in the queue for 90 seconds with nothing picking it up is run
once, server-side, the next time the page loads — through the same
`queue:work --once` code path, so request → build → download completes even on
a machine whose cron is missing or fires only every few minutes. Cron remains
the mechanism that works when nobody is watching the page.

### Measuring it

`enrolees:seed-bulk` creates rows for exactly this purpose, using the factories
and a small placeholder photograph each:

```bash
php artisan enrolees:seed-bulk --count=5000 --photos --force
php artisan enrolees:seed-bulk --purge --force          # removes what it created
```

Generated rows carry `Bulkseed-` in the surname, which is what makes `--purge`
exact. The command refuses to run outside local/testing without `--force`, and
refuses to add to a database that already holds enrolees without it. Run
`--purge` on its own to clear them; pass `--count` as well to replace rather
than clear.

Measured on this codebase with the bulk 5,000-row dataset, unfiltered, on the
development machine — the CSV and XLSX figures are the `queue:work -v` timings
above, the memory figures from running the job in-process:

| Format | Rows | Time | Size | Peak memory |
|---|---|---|---|---|
| CSV | 5,000 | 5.5s | 1.6 MB | 28 MB |
| XLSX | 5,000 | 8.3s | 938 KB | 28 MB |
| Photos (ZIP) | 4,997 | 1.9s | 5.8 MB | 24 MB |

The photo bundle is 4,997 rather than 5,000 because three photographs were
deleted from disk before that run, which is the missing-file case below: the
export still finished, at 4,997 rows, after logging three warnings.

Memory does not grow with the row count — 17 rows peaks at 24 MB, 108 at 26 MB,
1,666 at 28 MB and 5,000 at 28 MB. That is the point of streaming rows out as
they are read: the chunk size is the ceiling, not the total, so the same 64 MB
worker limit that a 50,000-row export will fit inside is enough today. It is not
a claim that memory never moves — the last 4 MB is the spreadsheet writer being
loaded at all.

Three things were worth measuring rather than assuming, and two of them were not
where they were expected:

- `ageInYears()` was calling Carbon's `->age`, which took 1.67s of a 4.87s
  export — a third of the runtime, for arithmetic. It now uses integers, and is
  93x faster.
- The per-row `Storage::exists()` check on each photograph looked like the
  obvious culprit and was not: 0.07s for 2,587 rows. It was left alone, because
  removing it would have changed what the `photo_file` column means.
- Relationships were already eager-loaded. Removing the eager loads to compare
  cost 11s instead of 0.65s, which is why the columns they select are now
  written down rather than left implicit.

A photograph that is recorded but absent from disk does not fail the bundle. The
job skips it, logs one warning naming the path, and logs a summary line with the
bundled and skipped counts, so a bundle that quietly came back 40 rows short is
visible rather than merely wrong. Verified against real files: three photographs
deleted, bundle finished at 4,997 of 5,000, three warnings and one summary in the
log, export status `ready`.

A ZIP of JPEG photographs is written with no compression at all. Photographs are
already compressed, so deflating them costs CPU and saves nothing — the bundle is
stored, not deflated, which is why 4,997 of them come to 5.8 MB in 1.9s.

---

## Deployment

See **[deployment/DEPLOYMENT-GUIDE.md](deployment/DEPLOYMENT-GUIDE.md)**.

The short version: document root must be `public/`; set `.env` (`APP_DEBUG=false`,
a 32-byte `APP_KEY`, database credentials); `migrate` and `db:seed`; and add the
two cron entries — one to drain the export queue, one to prune expired export
files. Without the queue entry, exports stay pending forever.

---

## Configuration worth knowing

| Key | Default | Meaning |
|---|---|---|
| `EXPORT_RETAIN_HOURS` | `24` | how long a finished export can be downloaded |
| `EXPORT_QUEUE` | `exports` | named queue, so a slow export cannot delay anything else |
| `EXPORT_MAX_PHOTO_BUNDLE_ROWS` | `5000` | refuses an oversized photo bundle instead of building it |
| `SESSION_IDLE_MINUTES` | `30` | idle timeout, enforced in middleware as well as by the cookie |
| `HASH_DRIVER` | `argon2id` | required by policy |

---

## Layout

```
app/
  Enums/            Role, EnroleeStatus, Gender, MaritalStatus, Education
  Http/
    Controllers/    Auth, Enrolee, Photo, Location, Admin/{User,Export,AuditLog,Location}
    Middleware/     active account, password changed, idle timeout, security headers
    Requests/       one per form; all validation lives here
  Jobs/             GenerateExport
  Models/           User, Enrolee, Program, Lga, Ward, Facility, Export, AuditLog
  Policies/         one per protected model
  Services/         EnroleeService, PhotoService, ExportService, EnroleeExporter,
                    EnroleeFilters, FilterChips, LocationImporter, AuditLogger,
                    SpreadsheetSanitizer
  Console/Commands/ PruneExports, SeedBulkEnrolees
resources/views/    Blade templates; admin/ for the administrator screens
public/             css/app.css, js/app.js — no build step
database/data/      locations_clean.csv (27 / 276 / 279)
```
