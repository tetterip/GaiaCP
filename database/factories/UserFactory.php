<?php

namespace Database\Factories;

use App\Enums\AdminArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'super_admin' => true,
        ];
    }

    /**
     * An admin who opens only these areas.
     *
     * @param  list<AdminArea>  $areas
     */
    public function limitedTo(array $areas = []): static
    {
        return $this->state(fn (array $attributes) => [
            'super_admin' => false,
            'admin_areas' => array_map(fn (AdminArea $area) => $area->value, $areas),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
