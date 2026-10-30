<?php

namespace App\Invoicing;

use App\Models\ActivityLog;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues a credit note for some or all lines of an invoice, the only way to undo one: by hand from the invoice, or
 * from ReversePayment when the payment that paid its notice is reversed. The credit note copies the lines, tax,
 * seller and client details of the invoice, and is numbered in its own series. A line is credited once.
 */
class IssueCreditNote
{
    /** The reason ReversePayment gives, in English like the activity log; shown translated. */
    public const PAYMENT_REVERSED = 'Payment reversed';

    /**
     * @param  list<int>|null  $itemIds  the invoice lines to credit; null for every line not credited yet
     *
     * @throws RuntimeException when there is nothing left to credit
     */
    public function handle(TaxInvoice $invoice, ?array $itemIds = null, ?string $reason = null): TaxInvoice
    {
        if ($invoice->isCreditNote()) {
            throw new RuntimeException('A credit note can\'t be credited.');
        }

        return DB::transaction(function () use ($invoice, $itemIds, $reason) {
            // The invoice row is the lock: two credit notes at once never credit the same line.
            TaxInvoice::query()->lockForUpdate()->whereKey($invoice->id)->first();

            $items = $invoice->creditableItems();

            if ($itemIds !== null) {
                $items = $items->whereIn('id', $itemIds)->values();
            }

            if ($items->isEmpty()) {
                throw new RuntimeException('Every chosen line of this invoice has been credited already.');
            }

            $date = today();
            $taxCents = (int) $items->sum('tax_cents');
            $totalCents = (int) $items->sum('total_cents');

            $note = TaxInvoice::create([
                'type' => TaxInvoice::CREDIT_NOTE,
                'number' => InvoiceNumbers::take($date, TaxInvoice::CREDIT_NOTE),
                'invoice_id' => $invoice->invoice_id,
                'credited_invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'date' => $date,
                'currency' => $invoice->currency,
                'seller' => IssueInvoice::seller(),
                'bill_to' => $invoice->bill_to,
                'subtotal_cents' => $totalCents - $taxCents,
                'tax_name' => $invoice->tax_name,
                'tax_rate' => $invoice->tax_rate,
                'tax_inclusive' => $invoice->tax_inclusive,
                'tax_cents' => $taxCents,
                'tax_note' => $invoice->tax_note,
                'reason' => filled($reason) ? $reason : null,
                'total_cents' => $totalCents,
            ]);

            $note->items()->createMany($items->map(fn (TaxInvoiceItem $item) => [
                'credited_item_id' => $item->id,
                'service_id' => $item->service_id,
                'description' => $item->description,
                'period_start' => $item->period_start,
                'period_end' => $item->period_end,
                'amount_cents' => $item->amount_cents,
                'tax_cents' => $item->tax_cents,
                'total_cents' => $item->total_cents,
            ])->all());

            ActivityLog::record('credit_note.issued', "Credit note {$note->number} issued for invoice {$invoice->number}", client: $invoice->client, properties: [
                'tax_invoice_id' => $note->id, 'credited_invoice_id' => $invoice->id, 'invoice_id' => $invoice->invoice_id,
            ]);

            return $note;
        });
    }
}
