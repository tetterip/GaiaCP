<?php

namespace App\Actions;

use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Invoicing\IssueCreditNote;
use App\Models\ActivityLog;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Undoes a payment recorded by mistake. Only the most recent payment of a
 * service can be reversed, and only while the due date is still the one that
 * payment set; otherwise the due date history would no longer add up.
 */
class ReversePayment
{
    public function handle(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $service = Service::query()->lockForUpdate()->findOrFail($payment->service_id);

            if (! self::canReverse($payment, $service)) {
                throw new RuntimeException('Only the latest payment can be reversed, and only while the due date is unchanged since.');
            }

            $service->update(['next_due_date' => $payment->due_date_before]);

            // Its payment notice owes that line again.
            foreach (InvoiceItem::query()->where('payment_id', $payment->id)->with('invoice')->get() as $item) {
                $item->update(['payment_id' => null]);

                if ($item->invoice->status === InvoiceStatus::Paid) {
                    $item->invoice->update(['status' => InvoiceStatus::Unpaid, 'paid_on' => null]);

                    // An invoice can't be changed: a credit note undoes it, and paying the notice again issues a new one.
                    if (($invoice = $item->invoice->taxInvoice) && ! $invoice->fullyCredited()) {
                        app(IssueCreditNote::class)->handle($invoice, reason: IssueCreditNote::PAYMENT_REVERSED);
                    }
                }
            }

            $payment->delete();

            // Paid from credit: the amount goes back into the balance.
            if ($payment->method === ClientCredit::METHOD) {
                app(ClientCredit::class)->record($service->client, CreditType::Refund, $payment->amount_cents, "Payment reversed: {$service->label}");
            }

            ActivityLog::record('payment.reversed', sprintf(
                'Payment of %s reversed; next due date back to %s',
                Money::format($payment->amount_cents, $payment->currency),
                $payment->due_date_before->toDateString(),
            ), $service, properties: ['payment_id' => $payment->id]);
        });
    }

    public static function canReverse(Payment $payment, ?Service $service = null): bool
    {
        $service ??= $payment->service;

        $latestId = $service->payments()->latest('id')->value('id');

        return $latestId === $payment->id
            && $service->next_due_date->equalTo($payment->due_date_after);
    }
}
