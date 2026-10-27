<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->subYear()->startOfDay();

        return [
            'client_id' => Client::factory(),
            'type' => ServiceType::Hosting,
            'server_id' => Server::factory(),
            'label' => 'Web hosting',
            'domain' => fake()->domainName(),
            'username' => 'u'.fake()->unique()->numerify('#######'),
            'package' => 'basic',
            'price_cents' => 6000,
            'currency' => 'EUR',
            'billing_cycle' => BillingCycle::Annually,
            'start_date' => $start,
            'next_due_date' => $start->copy()->addYear(),
            'status' => ServiceStatus::Active,
        ];
    }

    public function domain(): static
    {
        return $this->state([
            'type' => ServiceType::Domain,
            'server_id' => null,
            'username' => null,
            'package' => null,
            'label' => 'Domain registration',
            'price_cents' => 1500,
        ]);
    }

    public function maintenance(): static
    {
        return $this->state([
            'type' => ServiceType::Maintenance,
            'server_id' => null,
            'username' => null,
            'package' => null,
            'label' => 'Website maintenance',
            'price_cents' => 12000,
        ]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(['next_due_date' => $date]);
    }
}
