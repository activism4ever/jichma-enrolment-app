<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Defaults to a Program User so that a bare `User::factory()`
            // cannot accidentally create an account with cross-program reach.
            'role' => Role::ProgramUser->value,
            'program_id' => Program::factory(),
            'must_change_password' => false,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => Role::Admin->value,
            'program_id' => null,
        ]);
    }

    public function programUser(?Program $program = null): static
    {
        return $this->state(fn () => [
            'role' => Role::ProgramUser->value,
            'program_id' => $program?->id ?? Program::factory(),
        ]);
    }

    public function reviewer(?Program $program = null): static
    {
        return $this->state(fn () => [
            'role' => Role::Reviewer->value,
            'program_id' => $program?->id ?? Program::factory(),
        ]);
    }

    public function forProgram(Program $program): static
    {
        return $this->state(fn () => ['program_id' => $program->id]);
    }

    /** Holds a temporary password and must be forced to change it. */
    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}