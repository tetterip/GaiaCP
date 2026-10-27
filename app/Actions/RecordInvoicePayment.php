<?php

namespace App\Actions;

use App\Credit\ApplyCredit;
use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The client paid a payment notice: one payment per unpaid line, for the line's total (plus the late
 * renewal fees of that cycle), each through RecordPayment so due dates move forward one cycle and the
 * lines (then the notice) are marked paid. An upgrade's difference (App\Actions\ChangeProduct) is a payment that leaves the
 * due date as it is. An "Add funds" line goes into the client's credit balance instead,
 * which then pays the client's other unpaid notices when Settings → Client credit says so.
 */
class RecordInvoicePayment
{
    /** Registrar renewals that failed after the payments, by service label. */
    public array $renewalErrors = [];

    public function __construct(private RecordPayment $payments, private ClientCredit $credit) {}

    /** @return list<Payment> */
    public function handle(Invoice $invoice, CarbonImmutable $paidOn, string $method, ?string $reference = null): array
    {
        $this->renewalErrors = [];

        if ($invoice->status !== InvoiceStatus::Unpaid) {
            throw new RuntimeException("Payment notice {$invoice->number} is not unpaid.");
        }

        $items = $this->unpaid($invoice);
        $stale = $this->stale($items);

        if ($stale->isNotEmpty()) {
            throw new RuntimeException('These lines no longer match their service\'s due date: '.$stale->pluck('description')->join(', ')
                .'. Record those payments on the service, or cancel this notice.');
        }

        $recorded = [];

        // A late renewal fee is paid with its cycle's line, so the due date moves forward only once.
        $fees = $items->whereNotNull('fee')->where('fee', '!=', InvoiceItem::UPGRADE)->groupBy('service_id');

        foreach ($items->where('credit', true) as $item) {
            // One transaction: the line is marked as it is deposited, so a retry can't deposit it twice.
            DB::transaction(function () use ($invoice, $item, $method, $reference) {
                if (InvoiceItem::query()->lockForUpdate()->whereKey($item->id)->whereNull('credit_transaction_id')->doesntExist()) {
                    return;
                }

                $deposit = $this->credit->record($invoice->client, CreditType::Deposit, $item->total_cents, "Payment notice {$invoice->number}", [
                    'invoice_id' => $invoice->id, 'method' => $method, 'reference' => $reference,
                ]);
                $item->update(['credit_transaction_id' => $deposit->id]);
            });
        }

        foreach ($items->whereNull('fee')->where('credit', false) as $item) {
            $amount = $item->total_cents + (int) $fees->get($item->service_id)?->sum('total_cents');
            $recorded[] = $this->payments->handle($item->service, $amount, $paidOn, $method, $reference, "Payment notice {$invoice->number}");

            if ($this->payments->renewalError !== null) {
                $this->renewalErrors[$item->description] = $this->payments->renewalError;
            }
        }

        foreach ($items->where('fee', InvoiceItem::UPGRADE) as $item) {
            $recorded[] = $this->payUpgrade($item, $item->total_cents, $paidOn, $method, $reference, "Payment notice {$invoice->number}");
        }

        // Lines whose service is gone have nothing to pay through; settle the rest.
        $invoice->refresh()->settle($paidOn);

        // New funds pay what the client still owes elsewhere.
        if ($items->contains('credit', true) && ClientCredit::autoApply()) {
            $apply = app(ApplyCredit::class);

            try {
                $apply->forClient($invoice->client, $paidOn);
            } catch (Throwable $e) {
                report($e); // the deposit stands; the daily run tries again
            }

            $this->renewalErrors += $apply->renewalErrors;
        }

        return $recorded;
    }

    /**
     * Pays the difference of a change of product (an "upgrade" line): a payment on the service that leaves its due
     * date where it is.
     */
    public function payUpgrade(InvoiceItem $item, int $amountCents, CarbonImmutable $paidOn, string $method, ?string $reference = null, ?string $notes = null): Payment
    {
        $payment = DB::transaction(function () use ($item, $amountCents, $paidOn, $method, $reference, $notes) {
            $service = Service::query()->lockForUpdate()->findOrFail($item->service_id);

            $payment = $service->payments()->create([
                'amount_cents' => $amountCents,
                'currency' => $service->currency,
                'paid_on' => $paidOn,
                'method' => $method,
                'reference' => $reference,
                'due_date_before' => $service->next_due_date,
                'due_date_after' => $service->next_due_date,
                'recorded_by' => auth()->id(),
                'notes' => $notes,
            ]);

            $item->update(['payment_id' => $payment->id]);
            $item->invoice->settle($paidOn);

            ActivityLog::record('payment.recorded', sprintf('Payment of %s recorded for the change of product; next due date stays %s',
                Money::format($amountCents, $service->currency), $service->next_due_date->toDateString()), $service, properties: ['payment_id' => $payment->id]);

            return $payment;
        });

        // An add-on the client added waits for this payment.
        ClientAddons::activate($item->service()->firstOrFail());

        return $payment;
    }

    /**
     * Lines that no longer match their service: a line only matches while its service is still due on that date;
     * otherwise the payment would pay another cycle.
     *
     * @param  Collection<int, InvoiceItem>  $items  unpaid()
     * @return Collection<int, InvoiceItem>
     */
    public function stale(Collection $items): Collection
    {
        // An upgrade's difference is paid whatever the due date is now.
        return $items->reject(fn (InvoiceItem $item) => $item->credit || ($item->service !== null
            && ($item->fee === InvoiceItem::UPGRADE || $item->service->next_due_date->equalTo($item->due_date))));
    }

    /** @return Collection<int, InvoiceItem> */
    public function unpaid(Invoice $invoice): Collection
    {
        return $invoice->items()->unpaid()->with('service')->get();
    }
}
