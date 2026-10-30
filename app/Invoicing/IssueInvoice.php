<?php

namespace App\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\TaxInvoice;
use App\Payments\PaymentMethods;
use Illuminate\Support\Facades\DB;

/**
 * Issues the invoice of a paid payment notice (Settings → Invoices): called by Invoice::settle() as the notice is paid,
 * or by hand from the notice. One invoice per notice, so it is safe to call again. An "Add funds" notice gets none (the
 * notices that credit pays later do), nor does a notice of nothing to pay.
 */
class IssueInvoice
{
    public static function enabled(): bool
    {
        return (bool) config('gaiacp.invoicing.enabled');
    }

    /** Whether $notice is one that gets an invoice, issued or not. */
    public static function invoiceable(Invoice $notice): bool
    {
        return $notice->status === InvoiceStatus::Paid && ! $notice->credit && $notice->total_cents > 0;
    }

    public function handle(Invoice $notice): ?TaxInvoice
    {
        if (! self::invoiceable($notice)) {
            return null;
        }

        return DB::transaction(function () use ($notice) {
            // The notice row is the lock: two payments settling it at once issue one invoice.
            Invoice::query()->lockForUpdate()->whereKey($notice->id)->first();

            if ($existing = TaxInvoice::query()->where('invoice_id', $notice->id)->where('type', TaxInvoice::INVOICE)->first()) {
                return $existing;
            }

            $date = today();
            $items = $notice->items()->where('credit', false)->orderBy('id')->get();
            $methods = Payment::query()->whereIn('id', $items->pluck('payment_id')->filter())->pluck('method')->unique()
                ->map(fn (string $method) => PaymentMethods::label($method) ?? $method)->join(', ');

            $invoice = TaxInvoice::create([
                'number' => InvoiceNumbers::take($date),
                'invoice_id' => $notice->id,
                'client_id' => $notice->client_id,
                'date' => $date,
                'paid_on' => $notice->paid_on,
                'currency' => $notice->currency,
                'seller' => self::seller(),
                'bill_to' => $notice->bill_to ?? Invoice::billToOf($notice->client),
                'subtotal_cents' => $notice->subtotal_cents,
                'tax_name' => $notice->tax_name,
                'tax_rate' => $notice->tax_rate,
                'tax_inclusive' => $notice->tax_inclusive,
                'tax_cents' => $notice->tax_cents,
                'tax_note' => $notice->tax_note,
                'total_cents' => $notice->total_cents,
                'payment_methods' => $methods !== '' ? $methods : null,
            ]);

            // A late renewal fee has no period of its own; the others cover due date to the day before period_end.
            $invoice->items()->createMany($items->map(fn (InvoiceItem $item) => [
                'service_id' => $item->service_id,
                'description' => $item->description,
                'period_start' => $item->fee !== null && $item->fee !== InvoiceItem::UPGRADE ? null : $item->due_date,
                'period_end' => $item->fee !== null && $item->fee !== InvoiceItem::UPGRADE ? null : $item->period_end->subDay(),
                'amount_cents' => $item->amount_cents,
                'tax_cents' => $item->tax_cents,
                'total_cents' => $item->total_cents,
            ])->all());

            ActivityLog::record('tax_invoice.issued', "Invoice {$invoice->number} issued for payment notice {$notice->number}", client: $notice->client, properties: [
                'tax_invoice_id' => $invoice->id, 'invoice_id' => $notice->id,
            ]);

            return $invoice;
        });
    }

    /** @return array<string, string|null> the seller printed on the invoice: Settings → Company as it is today */
    public static function seller(): array
    {
        return collect(['name', 'address', 'vat_number', 'tax_office', 'email', 'phone', 'website'])
            ->mapWithKeys(fn (string $key) => [$key => config("gaiacp.company.{$key}")])->all();
    }
}
