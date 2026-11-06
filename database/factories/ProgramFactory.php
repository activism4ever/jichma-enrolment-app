<?php

namespace Database\Factories;

use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Program>
 */
class ProgramFactory extends Factory
{
    protected $model = Program::class;

    public function definition(): array
    {
        $code = fake()->unique()->lexify('????');

        return [
            'code' => $code,
            'name' => $code,
            'description' => null,
            // Active and non-sensitive by default, matching the database
            // defaults, so a bare Program::factory() is a usable programme.
            'is_active' => true,
            'is_sensitive' => false,
        ];
    }

    public function plhiv(): static
    {
        // Sensitive as well as named PLHIV: the old hard-coded check treated
        // this code as sensitive data, and the column now has to say so.
        return $this->state(fn () => ['code' => 'PLHIV', 'name' => 'PLHIV', 'is_sensitive' => true]);
    }

    public function cemog(): static
    {
        return $this->state(fn () => ['code' => 'CEMOG', 'name' => 'CEMOG']);
    }

    public function vestilar(): static
    {
        return $this->state(fn () => ['code' => 'VESTILAR', 'name' => 'VESTILAR']);
    }

    public function sensitive(): static
    {
        return $this->state(fn () => ['is_sensitive' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}