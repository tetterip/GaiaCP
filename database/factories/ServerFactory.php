<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Server '.fake()->unique()->numberBetween(1, 999),
            'type' => 'cpanel',
            'hostname' => fake()->domainName(),
            'port' => 2087,
            'username' => 'root',
            'api_token' => fake()->sha256(),
            'active' => true,
        ];
    }

    public function plesk(): static
    {
        return $this->state(['type' => 'plesk', 'port' => 8443, 'username' => 'admin']);
    }

    /** An access key login (no username). */
    public function directAdmin(): static
    {
        return $this->state(['type' => 'directadmin', 'port' => 2222, 'username' => 'admin', 'api_token' => 'LOGINKEY'.str_repeat('k', 56)]);
    }

    public function hestia(): static
    {
        return $this->state(['type' => 'hestia', 'port' => 8083, 'username' => null, 'api_token' => 'AKEY0000000000000001:'.str_repeat('s', 40)]);
    }
}
