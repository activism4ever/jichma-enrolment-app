# Deployment guide — JICHMA Enrolment

Target: Truehost (or any cPanel shared host) with PHP 8.2+, MySQL 8, and no SSH
access guaranteed.

The two things that differ from a typical Laravel deployment are called out here
because they are the ones that silently break on shared hosting:

1. **Exports run through a queued job.** Without the cron entry the work never
   happens and every export sits at "pending" forever.
2. **Photographs and exports live outside the web root** on the private disk, so
   nothing sensitive is ever served as a static file.

Two cron entries are required: one to drain the exports queue, one to run the
scheduler that deletes expired export files. See section 4.

---

## 1. Requirements checklist

| Requirement | Why |
|---|---|
| PHP **8.2** or newer | `readonly` promoted properties, enums |
| Extensions: `pdo_mysql`, `mbstring`, `openssl`, `gd`, `fileinfo`, `zip`, `finfo` | `gd` and `fileinfo` for photographs, `zip` for photo bundles, `finfo` for MIME sniffing |
| MySQL **8.0** or newer | the duplicate-NIN rule relies on a real unique index and case-insensitive collation |
| Composer 2 | — |
| `memory_limit` **256M** | XLSX generation is the only memory-hungry path |
| `max_execution_time` **30**+ | ordinary requests are all short; exports are queued precisely so they are not |

GD must be available. Photograph processing is done in PHP, not by a CLI tool,
because shared hosts do not give you ImageMagick reliably.

---

## 2. Upload and configure

Upload the project to something like `~/enrolment`. Put the document root at
`public/` — either an `Addon Domain` subdomain, or a `public_html` symlink:

```bash
ln -s ~/enrolment/public ~/public_html
```

If you cannot symlink, ask Truehost support to point the document root at
`public/`. **Do not** serve the project root: `storage/`, `.env` and
`database/` must stay unreachable over HTTP.

### `.env`

```bash
cp .env.example .env
```

Then edit:

- `APP_URL` — must match the real URL, including scheme. Used in signed and
  absolute links.
- `APP_DEBUG=false` — always. A stack trace on a production server leaks paths,
  queries and occasionally credentials.
- `APP_KEY` — generate with `php artisan key:generate` if you have SSH. If you
  do not, generate one yourself and paste it in:

  ```bash
  php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
  ```

  The key **must** decode to exactly 32 bytes. A shorter value fails at boot
  with `Unsupported cipher or incorrect key length`.

- `DB_*` — from cPanel's **MySQL Databases**. Create a user, grant it *all*
  privileges on that one database only.
- `ADMIN_EMAIL` / `ADMIN_PASSWORD` — the bootstrap administrator, used once by
  the seeder. If the password contains a `#`, wrap the value in quotes:
  `ADMIN_PASSWORD="Change#Me2026"`. Otherwise `.env` treats everything after the
  `#` as a comment and you get a truncated password.

---

## 3. Install

Over SSH:

```bash
cd ~/enrolment
composer install --no-dev --optimize-autoloader
php artisan key:generate          # if you did not set APP_KEY by hand
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link          # not needed for the private disk, but harmless
```

`db:seed` prints:

```
Locations: 27 LGAs, 276 wards, 279 facilities.
```

and creates the administrator. Sign in, change the password when prompted, then
issue real accounts from **Users** and deactivate the bootstrap one.

### Without SSH

Truehost's cPanel terminal is usually enough for the above. If there is
genuinely no shell, this application has **no** web installer: there is no
route that runs `migrate` or `Artisan::call()` from a browser, and adding one
would mean a permanent unauthenticated path to code execution on the server.
Use the cPanel terminal, or ask support to run three commands for you.

---

## 4. Cron — required

**Two entries, both every minute.** In cPanel: **Cron Jobs**, set to run once
per minute, command:

```cron
* * * * * cd /home/USERNAME/enrolment && php artisan queue:work --queue=exports --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

```cron
* * * * * cd /home/USERNAME/enrolment && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/home/USERNAME/enrolment` with your own path, and `php` with the full
path to your PHP binary if `/usr/bin/php` is not the right one — run
`which php` in the cPanel terminal and use what it prints.

- **The first drains the export queue.** Without it, exports stay "pending"
  forever and nobody can download anything. The database queue driver is used
  deliberately: shared hosting has no supervisor, so a worker that starts, does
  its work and exits is the right shape here.
  - `--stop-when-empty` makes the worker process what is there and exit, rather
    than holding the process open forever.
  - `--max-time=55` caps each run at just under a minute, so a slow job cannot
    still be running when the next minute's cron fires and two workers end up
    processing the same queue.
  - `--queue=exports` matches the queue named in `config/exports.php`.
  - **There is deliberately no `--tries`.** `GenerateExport` declares
    `$tries = 1`, and Laravel lets a job's own value win over the command line
    (`Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts`), so passing
    `--tries=3` here would do nothing at all. One attempt is the right number:
    a retry would start the export again from row zero while the exports page is
    still showing it as processing, and the administrator would be looking at two
    runs of the same request. A failure is reported as failed with the reason
    rather than retried behind their back.
  - **The exports page has a fallback of its own.** A job that has waited
    90 seconds with nothing picking it up is run once, server-side, the next
    time an administrator loads the exports page — through the same
    `queue:work --once` code path. It protects a fresh install whose cron is
    not configured yet and shortens the wait where cron only fires every
    10–15 minutes. It does not replace the cron entry, which is what processes
    exports when nobody has the page open.
- **The second runs the schedule** in `routes/console.php`, which is what
  actually deletes the files behind expired exports. Without it, passport
  photographs and NINs accumulate on disk forever even though their download
  links have stopped working.

To confirm the schedule is running, from the cPanel terminal:

```bash
php artisan schedule:list
```

That should list three daily entries: `exports:prune`, `model:prune --model='App\Models\Export'`
and `queue:prune-failed`. To run a prune by hand — useful after a long gap
without cron:

```bash
php artisan exports:prune --dry-run   # list what would go
php artisan exports:prune             # actually delete it
```

It removes two things. The obvious one is the file behind an export whose
download link has been dead for `--days`. The less obvious one is the staging
file of an export that never finished: a worker killed outright — out of memory,
or the host pulling the plug — cannot clean up after itself, and no database row
points at what it left, so nothing else in the application would ever remove it.
Those are only collected once they are at least ten minutes old, which is the
job's own time limit, so the sweep cannot delete the file an export is writing
right now.

A bulk dataset for testing is one command, and it refuses to touch a production
database without `--force` being passed on top of it:

```bash
php artisan enrolees:seed-bulk --count=5000 --photos   # local and testing only
php artisan enrolees:seed-bulk --purge                 # removes what it created
```

Never run `--force` against production data. Rows it creates carry `Bulkseed-`
in the surname, which is how `--purge` finds exactly its own rows and nothing
else.

> **If your host ignores "every minute".** Truehost often runs cron only every
> 10-15 minutes whatever you set. Nothing breaks: an export that waits a quarter
> of an hour still completes, and the prune only removes files whose download
> link expired at least a day ago, so running late is harmless.

---

## 5. Storage permissions

```bash
chmod -R 775 storage bootstrap/cache
```

Both the web server user and the CLI user must be able to write, because the
queue worker runs from cron under your account while requests run under the
web server user.

Photographs and exports go to `storage/app/private/` (the `private` disk). That
directory is outside `public/` and has no URL mapping: the only way to read a
file is through a controller that re-checks authorisation.

---

## 6. Verify before handing over

Run through this list on the real server. Each item has caught a real
misconfiguration:

- [ ] Sign in as the administrator; the forced password change appears.
- [ ] Create a Program User; sign in as them and confirm they cannot reach
      `/admin` at all.
- [ ] Save a draft, submit it with a photograph, and confirm the photograph
      appears through the record page.
- [ ] Request a CSV export. No password is asked for — queuing is audited, and
      the job re-derives the query from your own visibility when it runs.
      Reload **Export** after a minute: the row should read "Processed N of N"
      and the download link should work. A row stuck on "Queued" means cron is
      not draining the queue.
- [ ] Confirm `php artisan schedule:list` lists `exports:prune`, and that the
      `schedule:run` cron entry is present. Without it, expired files stay on
      disk forever.
- [ ] Check `storage/logs/laravel.log` is writable and not world-readable.
- [ ] Confirm `https://enrolment.example.com/.env` returns 404.
- [ ] Confirm `https://enrolment.example.com/storage/app/private/` returns 404.
- [ ] Check **Audit log** records each of the above.

---

## 7. Backups

Two things need backing up, and they are different:

| What | Why | How |
|---|---|---|
| The MySQL database | every registration, account and audit entry | cPanel **Backup** or a scheduled `mysqldump` |
| `storage/app/private` | passport photographs | `rsync`/FTP; it is not in the database |

A database backup alone restores the records but leaves every photograph
missing, which makes historical registrations incomplete. Back up both.

---

## 8. Known security advisories

`composer audit` reports advisories against the Laravel 11 packages this project
pins. They are not all reachable in this application, and upgrading to Laravel 12
is a larger change than should be made as part of a deployment.

What has been done instead:

| Advisory class | Mitigation in this codebase |
|---|---|
| Information disclosure via debug output | `APP_DEBUG=false`; error pages render no stack trace |
| Unvalidated redirect / open redirect | no redirect target is ever taken from user input |
| Mass assignment of privilege columns | `User::$fillable` is `name`, `email`, `password`; `role`, `program_id`, `is_active` and `must_change_password` are assigned explicitly |
| Session fixation | session ID regenerated on login and on password change |
| CSRF | `VerifyCsrfToken` on every state-changing route |
| Email header injection | the application sends no mail at all; `MAIL_MAILER=log` |
| Spreadsheet formula injection in exports | `SpreadsheetSanitizer` prefixes any cell starting with `=`, `+`, `-` or `@` |

Track the advisories and plan the Laravel 12 upgrade as scheduled work rather
than a deployment-day surprise.

---

## 9. Upgrading later

```bash
cd ~/enrolment
git pull                                   # or upload the new release
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

After any `.env` change: `php artisan config:clear && php artisan config:cache`.

Take a database backup first. `migrate` is not reversible in production — there
is no `migrate:rollback` step in a release, only forward migrations.
