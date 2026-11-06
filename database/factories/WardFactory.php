<?php

namespace Database\Factories;

use App\Models\Lga;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ward>
 */
class WardFactory extends Factory
{
    protected $model = Ward::class;

    public function definition(): array
    {
        return [
            'lga_id' => Lga::factory(),
            // The unique() call wraps the whole composed name, not just the
            // suffix. Faker has only 18 city suffixes, so keying uniqueness on
            // one alone threw an OverflowException after 18 wards — and because
            // every enrolee builds its own facility, ward and LGA, that quietly
            // capped any test or seed that created more than a handful of rows.
            // The trailing letters and digits widen the pool to a size that
            // survives a few hundred rows while the name still reads like a ward.
            'name' => fake()->unique()->bothify(fake()->citySuffix().' Ward ??###'),
        ];
    }

    public function forLga(Lga $lga): static
    {
        return $this->state(fn () => ['lga_id' => $lga->id]);
    }
}