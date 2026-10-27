<?php

namespace App\Invoices;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Support\Tax;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the unpaid payment notice already issued for a service's next due date in step with the service: after its
 * price, first payment or options change (the service form, App\Actions\ChangeProduct), the line bills what a new
 * notice would, and the notice's totals (and so its order's total) follow. Paid and cancelled notices are left as
 * they are. Safe to re-run.
 */
class RepriceIssuedNotice
{
    /** What a notice bills a service for its next due date: its first payment until it was ever paid. */
    public static function amountFor(Service $service): int
    {
        // A service that was never paid owes its first payment (e.g. a domain's registration price).
        // A scheduled price change (ChangePrice) bills its new price from its date on.
        return ! $service->payments()->exists() && $service->first_payment_cents !== null
            ? $service->first_payment_cents
            : $service->priceOn($service->next_due_date);
    }

    /** The service's unpaid line on a notice issued for its next due date. */
    public static function issuedLine(Service $service): ?InvoiceItem
    {
        return InvoiceItem::query()->where('service_id', $service->id)->whereNull('fee')->whereNull('payment_id')
            ->whereDate('due_date', $service->next_due_date)
            ->whereHas('invoice', fn (Builder $q) => $q->unpaid())->with('invoice')->first();
    }

    /** @return Invoice|null the notice it changed */
    public function handle(Service $service): ?Invoice
    {
        $line = self::issuedLine($service);
        $amount = self::amountFor($service);
        $description = GenerateInvoices::describe($service);

        if ($line === null || ($line->amount_cents === $amount && $line->description === $description)) {
            return null;
        }

        $invoice = $line->invoice;
        $old = $line->amount_cents;
        $tax = Tax::apply($amount, $invoice->tax_rate, $invoice->tax_inclusive);
        $line->update(['description' => $description, 'amount_cents' => $amount, 'tax_cents' => $tax['tax'], 'total_cents' => $tax['total']]);

        $items = $invoice->items()->get();
        $invoice->update([
            'subtotal_cents' => $items->sum('total_cents') - $items->sum('tax_cents'),
            'tax_cents' => $items->sum('tax_cents'),
            'total_cents' => $items->sum('total_cents'),
        ]);

        if ($old !== $amount) {
            ActivityLog::record('invoice.line_repriced', "Payment notice {$invoice->number}: {$service->label} at the new price", $service, properties: ['invoice_id' => $invoice->id, 'from_cents' => $old, 'to_cents' => $amount]);
        }

        return $invoice;
    }
}
