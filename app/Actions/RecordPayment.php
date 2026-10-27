<?php

namespace App\Actions;

use App\Enums\AutoSetup;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Records a manual payment for one billing cycle: the due date moves forward
 * one cycle, and a service suspended for non-payment becomes active again
 * (on the panel too, for hosting). A payment on an active or suspended
 * domain also renews it at its registrar for one cycle. The payment notice line for that
 * cycle is marked paid, and the notice with it once all its lines are.
 */
class RecordPayment
{
    /** Why the registrar renewal after the last payment failed, for the admin; null when it did not fail. */
    public ?string $renewalError = null;

    /** Why setting up the service on its first payment failed; null when it did not fail. */
    public ?string $setupError = null;

    public function __construct(private ServiceLifecycle $lifecycle, private DomainLifecycle $domains, private SetUpService $setUp) {}

    public function handle(
        Service $service,
        int $amountCents,
        CarbonInterface $paidOn,
        string $method,
        ?string $reference = null,
        ?string $notes = null,
    ): Payment {
        $this->renewalError = null;
        $this->setupError = null;

        // Read before the transaction: the payment does not change the status.
        $renewDomain = $this->domains->shouldRenewOnPayment($service->fresh());

        $payment = DB::transaction(function () use ($service, $amountCents, $paidOn, $method, $reference, $notes) {
            $service = Service::query()->lockForUpdate()->findOrFail($service->id);

            $before = $service->next_due_date;
            $after = $service->billing_cycle->advance($before, $service->anchorDay());

            $payment = $service->payments()->create([
                'amount_cents' => $amountCents,
                'currency' => $service->currency,
                'paid_on' => $paidOn,
                'method' => $method,
                'reference' => $reference,
                'due_date_before' => $before,
                'due_date_after' => $after,
                'recorded_by' => auth()->id(),
                'notes' => $notes,
            ]);

            $service->next_due_date = $after;
            $service->save();

            // The payment notice line for the cycle just paid, if one was issued, with any late renewal fee for it.
            $items = InvoiceItem::query()
                ->where('service_id', $service->id)
                ->forCycle()
                ->whereDate('due_date', $before)
                ->whereNull('payment_id')
                ->whereHas('invoice', fn ($q) => $q->unpaid())
                ->with('invoice')
                ->get();

            foreach ($items as $item) {
                $item->update(['payment_id' => $payment->id]);
            }

            $items->first()?->invoice->settle($paidOn);

            ActivityLog::record('payment.recorded', sprintf(
                'Payment of %s recorded; next due date %s → %s',
                Money::format($amountCents, $service->currency),
                $before->toDateString(),
                $after->toDateString(),
            ), $service, properties: ['payment_id' => $payment->id]);

            return $payment;
        });

        // Outside the transaction: the payment stands even if the panel is unreachable.
        $service = $service->fresh();

        if ($service->status === ServiceStatus::Suspended
            && $service->suspension_reason === Service::SUSPENDED_OVERDUE
            && ! $service->isOverdue()) {
            try {
                $this->lifecycle->unsuspend($service);
            } catch (ProvisioningException) {
                // Already in the activity log; the service stays suspended until unsuspended by hand.
            }
        }

        // A domain's payment renews it at the registrar; like the unsuspend, a failure keeps the payment.
        if ($renewDomain) {
            try {
                $this->domains->renew($service);
            } catch (ProvisioningException $e) {
                $this->renewalError = $e->getMessage(); // already in the activity log
            }
        }

        // A product set up "on the first payment": like the renewal, a failure keeps the payment.
        if ($service->status === ServiceStatus::Pending && $service->auto_setup === AutoSetup::OnPayment) {
            try {
                $this->setUp->handle($service);
            } catch (ProvisioningException $e) {
                $this->setupError = $e->getMessage(); // already in the activity log
            }
        }

        $service->fresh()->completeIfPaidOnce();

        return $payment;
    }
}
