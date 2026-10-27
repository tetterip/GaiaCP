<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company' => fake()->optional()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+30 69'.fake()->numerify('########'),
            'address1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Attica',
            'postcode' => fake()->numerify('1####'),
            'country' => 'GR',
        ];
    }
}
