<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Hosting Basic',
            'type' => ServiceType::Hosting,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 6000, 'setup_cents' => 0]],
            'currency' => 'EUR',
            'billing_cycle' => BillingCycle::Annually,
            'package' => 'basic',
        ];
    }

    public function maintenance(): static
    {
        return $this->state([
            'name' => 'Maintenance',
            'type' => ServiceType::Maintenance,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 12000, 'setup_cents' => 0]],
            'package' => null,
        ]);
    }

    /** One price (and setup fee) for a cycle, which becomes the default cycle. */
    public function priced(int $cents, BillingCycle $cycle = BillingCycle::Annually, int $setupCents = 0): static
    {
        return $this->state([
            'prices' => [$cycle->value => ['enabled' => true, 'price_cents' => $cents, 'setup_cents' => $setupCents]],
            'billing_cycle' => $cycle,
        ]);
    }
}
