<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Facility>
 */
class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    public function definition(): array
    {
        return [
            'ward_id' => Ward::factory(),
            'name' => fake()->unique()->company().' Health Centre',
        ];
    }

    public function forWard(Ward $ward): static
    {
        return $this->state(fn () => ['ward_id' => $ward->id]);
    }
}