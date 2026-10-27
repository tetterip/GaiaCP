<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'amount_cents' => 6000,
            'currency' => 'EUR',
            'paid_on' => now(),
            'method' => 'bank_transfer',
            'due_date_before' => now()->subYear(),
            'due_date_after' => now(),
        ];
    }
}
