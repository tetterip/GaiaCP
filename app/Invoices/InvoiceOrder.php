<?php

namespace App\Invoices;

use App\Credit\ApplyCredit;
use App\Credit\ClientCredit;
use App\Models\Invoice;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The payment notice for a new order: its unpaid items, due today, emailed right away
 * (Payment Notice Created) so the order is tracked as owed until it is paid. Safe to re-run.
 */
class InvoiceOrder
{
    public function __construct(private GenerateInvoices $generator, private SendInvoiceEmails $emails) {}

    public function handle(Order $order): ?Invoice
    {
        $today = CarbonImmutable::today();

        $created = $this->generator->handle($today, order: $order);

        if ($created === []) {
            return null;
        }

        // Credit pays what it can first (and the first payment sets the items up when they are set to).
        if (ClientCredit::autoApply()) {
            foreach ($created as $invoice) {
                try {
                    app(ApplyCredit::class)->toInvoice($invoice->refresh()->load('client'), $today);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->emails->handle($today, only: array_map(fn (Invoice $invoice) => $invoice->id, $created));

        return $created[0]->refresh();
    }
}
