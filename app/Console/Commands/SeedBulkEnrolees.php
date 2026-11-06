<?php

namespace App\Console\Commands;

use App\Enums\EnroleeStatus;
use App\Models\Enrolee;
use App\Models\Facility;
use App\Models\Program;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Create a large, believable body of enrolees for measuring export performance.
 *
 * Exports are the one part of this application whose cost is not visible from
 * the code. Forty rows write instantly; the same code path over a
 * whole-country table is what stalls the queue and makes an administrator think
 * the export is broken. This command exists so the numbers in the README are
 * measured rather than estimated, and so a regression can be caught by running
 * it twice and comparing.
 *
 * It is deliberately awkward to run by accident:
 *
 *   - outside local/testing it refuses without --force, because it writes rows
 *     that look exactly like real registrations, NINs and photographs included;
 *   - it refuses to add to a database that already holds enrolees unless
 *     --force is given, so fake rows cannot quietly blend into real data;
 *   - generated rows carry a marker in the surname, which is what makes
 *     --purge exact and prevents it deleting anything it did not create.
 *
 * Usage:
 *
 *   php artisan enrolees:seed-bulk --count=5000 --photos --force
 *   php artisan enrolees:seed-bulk --purge --force
 */
class SeedBulkEnrolees extends Command
{
    protected $signature = 'enrolees:seed-bulk
        {--count= : How many enrolees to create; defaults to 5000, and omitting it alongside --purge purges only}
        {--program= : Restrict them to one program code; spread over all programs when omitted}
        {--photos : Give each one a small placeholder photograph on the private disk}
        {--force : Required outside local/testing, and required to add to a populated database}
        {--purge : Delete previously generated enrolees before starting}';

    protected $description = 'Create bulk enrolees for measuring export performance';

    /**
     * Written into every generated surname. It makes the rows obviously
     * synthetic to anyone who later meets one in a list, and it is the only
     * thing --purge keys on.
     */
    private const MARKER = 'Bulkseed';

    public function handle(): int
    {
        // Purging is meant to be usable on its own. "--purge --count=5000"
        // reads like a request to replace the dataset, and silently appending
        // to the rows it just deleted is not what anyone means by that.
        if ($this->option('purge')) {
            $this->purge();

            if ($this->option('count') === null) {
                return self::SUCCESS;
            }
        }

        $count = max(1, (int) ($this->option('count') ?? 5000));

        if (! $this->option('force') && ! app()->environment('local', 'testing')) {
            $this->error('Refusing to bulk-seed outside local/testing without --force.');

            return self::FAILURE;
        }

        $existing = Enrolee::withTrashed()->count();

        if ($existing > 0 && ! $this->option('force')) {
            $this->error("There are already {$existing} enrolees. Re-run with --force to add alongside them.");

            return self::FAILURE;
        }

        $programs = $this->programs();

        if ($programs === []) {
            return self::FAILURE;
        }

        $userIds = User::query()->orderBy('id')->limit(25)->pluck('id')->all();

        if ($userIds === []) {
            $this->error('No users exist, so created_by could not be set. Run php artisan db:seed first.');

            return self::FAILURE;
        }

        // Reused rather than generated. Letting the factory create a facility per
        // row would add thousands of LGAs, wards and facilities to the
        // geography, which is not what this command is for — and the factory's
        // unique() ward names run out long before 5,000 rows.
        $facilities = Facility::query()->orderBy('id')->get()->all();

        // One generated image, written to each row's own path. Re-encoding
        // identical bytes per row would measure GD rather than the export.
        $photo = $this->option('photos') ? $this->placeholderPhoto() : null;

        $this->info(sprintf(
            'Creating %d enrolee(s) across %d program(s)%s.',
            $count,
            count($programs),
            $photo ? ' with placeholder photographs' : ''
        ));

        $started = microtime(true);
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            $program = $programs[$i % count($programs)];
            $facility = $facilities === [] ? null : $facilities[$i % count($facilities)];

            Enrolee::factory()->forProgram($program)->create(
                $this->attributes($program, $userIds, $facility, $photo)
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf('Created %d enrolee(s) in %.1fs.', $count, microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * @param  list<Program>  $programs
     * @return list<Program>
     */
    private function programs(): array
    {
        if ($code = $this->option('program')) {
            $program = Program::query()->where('code', $code)->first();

            if ($program) {
                return [$program];
            }

            $this->error("No program with code {$code}.");

            return [];
        }

        $all = Program::query()->orderBy('id')->get()->all();

        if ($all === []) {
            $this->error('No programs exist. Run php artisan db:seed first.');
        }

        return $all;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<string, mixed>
     */
    private function attributes(Program $program, array $userIds, ?Facility $facility, ?string $photo): array
    {
        // Spread over the last three months so a date-filtered export has
        // something to exclude, which is half of what makes a filter slow.
        $createdAt = now()->subSeconds(random_int(0, 60 * 60 * 24 * 90));

        return [
            'created_by' => $userIds[array_rand($userIds)],
            'status' => EnroleeStatus::Submitted->value,
            'surname' => self::MARKER.'-'.fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->boolean(60) ? fake()->firstName() : null,
            'gender' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'marital_status' => fake()->randomElement(['single', 'married', 'widowed', 'divorced']),
            'education' => fake()->randomElement(['none', 'primary', 'secondary', 'nce_ond', 'hnd_bsc', 'postgraduate']),
            'phone' => '080'.fake()->unique()->numerify('########'),
            'nin' => fake()->unique()->numerify('###########'),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'lga_id' => $facility?->ward?->lga_id,
            'ward_id' => $facility?->ward_id,
            'facility_id' => $facility?->id,
            'photo_path' => $photo === null ? null : $this->writePlaceholderPhoto($photo),
            'submitted_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** A tiny, valid JPEG, generated once. */
    private function placeholderPhoto(): string
    {
        $image = imagecreatetruecolor(120, 150);
        $background = imagecolorallocate($image, 235, 235, 240);

        imagefilledrectangle($image, 0, 0, 120, 150, $background);

        ob_start();
        imagejpeg($image, null, 70);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function writePlaceholderPhoto(string $bytes): string
    {
        $path = 'photos/'.Str::random(40).'.jpg';

        Storage::disk('private')->put($path, $bytes);

        return $path;
    }

    /**
     * Delete rows this command created, photographs included.
     *
     * Hard-deleted rather than soft-deleted: a soft-deleted row keeps its NIN
     * reserved and stays in the table, which would make the next measurement
     * slower and the row count wrong.
     *
     * Photographs are swept separately, keyed on being referenced by nothing.
     * An interrupted run leaves rows that never got written and files that no
     * row points at, and those files would otherwise make the next photo ZIP
     * benchmark quietly measure the remains of the last one.
     */
    private function purge(): void
    {
        $rows = Enrolee::withTrashed()
            ->where('surname', 'like', self::MARKER.'-%');

        if ($rows->count() === 0) {
            $this->line('No generated enrolees to purge.');
        } else {
            $purged = 0;

            $rows->chunkById(500, function ($chunk) use (&$purged) {
                foreach ($chunk as $row) {
                    if ($row->photo_path) {
                        Storage::disk('private')->delete($row->photo_path);
                    }

                    DB::table('enrolees')->where('id', $row->id)->delete();
                    $purged++;
                }
            });

            $this->line("Purged {$purged} generated enrolee(s).");
        }

        $this->purgeOrphanPhotographs();
    }

    /** Delete private photographs that no enrolee row refers to. */
    private function purgeOrphanPhotographs(): void
    {
        $disk = Storage::disk('private');

        if (! $disk->exists('photos')) {
            return;
        }

        $referenced = Enrolee::withTrashed()
            ->whereNotNull('photo_path')
            ->pluck('photo_path')
            ->flip();

        $orphans = 0;

        foreach ($disk->files('photos') as $file) {
            if (! $referenced->has($file)) {
                $disk->delete($file);
                $orphans++;
            }
        }

        if ($orphans > 0) {
            $this->line("Deleted {$orphans} orphaned photograph(s).");
        }
    }
}
