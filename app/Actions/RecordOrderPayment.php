<?php

namespace App\Actions;

use App\Enums\ServiceStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The client's first payment for an order: one payment per item that has none yet,
 * for that item's first-payment amount (a domain's registration price, else its price).
 * Each goes through RecordPayment, so due dates move forward one cycle as usual.
 */
class RecordOrderPayment
{
    /** @var array<string, string> item label => why setting it up on payment failed */
    public array $setupErrors = [];

    public function __construct(private RecordPayment $payments) {}

    /** @return list<Payment> */
    public function handle(Order $order, CarbonImmutable $paidOn, string $method, ?string $reference = null): array
    {
        $recorded = [];
        $this->setupErrors = [];

        foreach ($this->unpaid($order) as $service) {
            $recorded[] = $this->payments->handle($service, $service->firstPaymentCents(), $paidOn, $method, $reference, "Order #{$order->id}");

            if ($this->payments->setupError !== null) {
                $this->setupErrors[$service->label] = $this->payments->setupError;
            }
        }

        return $recorded;
    }

    /** @return Collection<int, Service> items with no payment yet */
    public function unpaid(Order $order)
    {
        return $order->services()
            ->whereIn('status', [ServiceStatus::Pending, ...ServiceStatus::billable()])
            ->whereDoesntHave('payments')
            ->get();
    }
}
