<?php

namespace App\Actions;

use App\Enums\ServiceStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The client's first payment for an order: one payment per item that has none yet, for what the order's payment notice
 * asks for it (with tax and late fees; without a notice, its first payment with tax).
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
            $recorded[] = $this->payments->handle($service, self::amount($service), $paidOn, $method, $reference, "Order #{$order->id}",
                RecordServicePayment::expected($service) !== null ? $service->next_due_date : null);

            if ($this->payments->setupError !== null) {
                $this->setupErrors[$service->label] = $this->payments->setupError;
            }
        }

        return $recorded;
    }

    /** What an item's first payment comes to: its line on the order's notice (with tax), else its first payment with tax. */
    public static function amount(Service $service): int
    {
        return RecordServicePayment::suggested($service);
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
