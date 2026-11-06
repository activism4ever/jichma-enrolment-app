<?php

namespace Database\Factories;

use App\Models\Lga;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lga>
 */
class LgaFactory extends Factory
{
    protected $model = Lga::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->city()];
    }
}