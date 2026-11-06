<?php

namespace Database\Factories;

use App\Enums\Education;
use App\Enums\EnroleeStatus;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Models\Enrolee;
use App\Models\Facility;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Enrolee>
 */
class EnroleeFactory extends Factory
{
    protected $model = Enrolee::class;

    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'created_by' => User::factory(),
            'status' => EnroleeStatus::Draft->value,
            'surname' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'gender' => Gender::Male->value,
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'marital_status' => MaritalStatus::Single->value,
            'education' => Education::Secondary->value,
            'phone' => '080'.fake()->numerify('########'),
            'nin' => fake()->unique()->numerify('###########'),
            'address' => fake()->address(),
            'facility_id' => Facility::factory(),
            'photo_path' => null,
        ];
    }

    /**
     * A draft with only the fields a user might reasonably have typed so far,
     * to prove that partial data saves.
     */
    public function partialDraft(): static
    {
        return $this->state(fn () => [
            'surname' => fake()->lastName(),
            'first_name' => null,
            'middle_name' => null,
            'gender' => null,
            'date_of_birth' => null,
            'marital_status' => null,
            'education' => null,
            'phone' => null,
            'nin' => null,
            'address' => null,
            'facility_id' => null,
            'photo_path' => null,
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => EnroleeStatus::Submitted->value,
            'submitted_at' => now(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => EnroleeStatus::Draft->value,
            'submitted_at' => null,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'created_by' => $user->id,
            'program_id' => $user->program_id ?? Program::factory(),
        ]);
    }

    public function forProgram(Program $program): static
    {
        return $this->state(fn () => ['program_id' => $program->id]);
    }

    public function withNin(string $nin): static
    {
        return $this->state(fn () => ['nin' => $nin]);
    }
}