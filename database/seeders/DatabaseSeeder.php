<?php

namespace Database\Seeders;

use App\Models\Program;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Baseline data: the three programs, the bootstrap administrator, and the
 * Jigawa State location list shipped with the project.
 *
 * Deliberately does not use env() for anything other than the administrator
 * bootstrap; every other value is a literal. The starting overlay called
 * env() directly, which breaks under config:cache on a shared host because the
 * cached config no longer re-reads .env at runtime.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPrograms();
        $this->seedAdmin();
        $this->importLocations(database_path('data/locations_clean.csv'));
    }

    private function seedPrograms(): void
    {
        foreach ([
            'CEMOG' => 'CEMOG',
            'VESTILAR' => 'VESTILAR',
            'PLHIV' => 'PLHIV',
        ] as $code => $name) {
            // PLHIV holds sensitive data: the column says so (the upgrade
            // migration backfills an existing PLHIV row, and a fresh seed must
            // reach the same state). is_sensitive is not fillable, so it is
            // set here explicitly rather than through updateOrCreate.
            $program = Program::updateOrCreate(['code' => $code], ['name' => $name]);

            if ($code === 'PLHIV') {
                $program->forceFill(['is_sensitive' => true])->save();
            }
        }
    }

    private function seedAdmin(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD');

        if (blank($password)) {
            $this->command?->warn('ADMIN_PASSWORD is not set; skipping administrator seed.');

            return;
        }

        $admin = DB::table('users')->where('email', $email)->first();

        $payload = [
            'email' => $email,
            'name' => 'Administrator',
            'password' => Hash::make($password),
            'role' => 'admin',
            'program_id' => null,
            'must_change_password' => true,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($admin) {
            // Preserve is_active: re-seeding must not silently re-activate an
            // administrator who was deliberately deactivated.
            $payload['is_active'] = $admin->is_active;
            unset($payload['role'], $payload['program_id'], $payload['must_change_password']);
            DB::table('users')->where('email', $email)->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table('users')->insert($payload);
        }
    }

    /**
     * Idempotent import of `lga,ward,facility`.
     *
     * Uses insert-or-ignore plus a lookup rather than a bare updateOrInsert so
     * that re-running the seeder never rewrites an existing row (and never
     * invalidates foreign keys held by existing enrolees).
     */
    private function importLocations(string $file): void
    {
        if (! is_readable($file)) {
            $this->command?->warn("Location file not found at {$file}; skipping.");

            return;
        }

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle); // lga,ward,facility

        if (! $handle) {
            $this->command?->warn("Could not open {$file}; skipping.");

            return;
        }

        DB::transaction(function () use ($handle) {
            $lgaCache = DB::table('lgas')->pluck('id', 'name')->all();
            $wardCache = DB::table('wards')->get(['id', 'lga_id', 'name'])
                ->mapWithKeys(fn ($w) => ["{$w->lga_id}|{$w->name}" => $w->id])->all();

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 3) {
                    continue;
                }

                [$lga, $ward, $facility] = array_map('trim', $row);

                if ($lga === '' || $ward === '' || $facility === '') {
                    continue;
                }

                if (! isset($lgaCache[$lga])) {
                    $lgaCache[$lga] = DB::table('lgas')->insertGetId(['name' => $lga]);
                }
                $lgaId = $lgaCache[$lga];

                $wardKey = "{$lgaId}|{$ward}";
                if (! isset($wardCache[$wardKey])) {
                    $wardCache[$wardKey] = DB::table('wards')->insertGetId([
                        'lga_id' => $lgaId,
                        'name' => $ward,
                    ]);
                }
                $wardId = $wardCache[$wardKey];

                DB::table('facilities')->insertOrIgnore([
                    'ward_id' => $wardId,
                    'name' => $facility,
                ]);
            }
        });

        fclose($handle);

        $this->command?->info(sprintf(
            'Locations: %d LGAs, %d wards, %d facilities.',
            DB::table('lgas')->count(),
            DB::table('wards')->count(),
            DB::table('facilities')->count(),
        ));
    }
}