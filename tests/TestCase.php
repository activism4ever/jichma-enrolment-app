<?php

namespace Tests;

use App\Models\Enrolee;
use App\Models\Facility;
use App\Models\Lga;
use App\Models\Program;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Shared helpers for feature tests.
 *
 * RefreshDatabase is applied here rather than per test so that the whole suite
 * runs against the real MySQL schema. That matters: the duplicate-NIN rule
 * depends on a MySQL unique index, and a nullable column inside a unique index
 * behaves differently under SQLite.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** A minimal, fully consistent location chain. */
    protected function locationChain(): array
    {
        $lga = Lga::factory()->create(['name' => 'Auyo']);
        $ward = Ward::factory()->forLga($lga)->create(['name' => 'Auyakayi']);
        $facility = Facility::factory()->forWard($ward)->create(['name' => 'Auyakayi BHC']);

        return compact('lga', 'ward', 'facility');
    }

    /**
     * A complete set of valid enrolee attributes, so tests that are not about
     * validation do not have to spell out fourteen fields each time.
     */
    protected function validEnroleePayload(array $overrides = []): array
    {
        ['facility' => $facility] = $this->locationChain();

        return array_merge([
            'surname' => 'Bello',
            'first_name' => 'Amina',
            'middle_name' => 'Yusuf',
            'gender' => 'female',
            'date_of_birth' => '1990-04-12',
            'marital_status' => 'married',
            'education' => 'hnd_bsc',
            'phone' => '08031234567',
            'nin' => '12345678901',
            'address' => '12 Zaria Road, Auyo',
            'lga_id' => $facility->ward->lga_id,
            'ward_id' => $facility->ward_id,
            'facility_id' => $facility->id,
        ], $overrides);
    }

    protected function program(string $code = 'CEMOG'): Program
    {
        return Program::firstOrCreate(
            ['code' => $code],
            // PLHIV is sensitive in production; mirror that here so tests of the
            // masking and programme rules describe the real installation.
            ['name' => $code, 'is_sensitive' => $code === 'PLHIV'],
        );
    }

    /** Counts enrolees visible to a user, mirroring the list query. */
    protected function visibleCountTo($user): int
    {
        return Enrolee::visibleTo($user)->count();
    }
}