<?php

namespace App\Credit;

use App\Enums\InvoiceStatus;
use App\Invoices\SendInvoiceEmails;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Add funds": a payment notice (invoices.credit) with one line for the amount, paid like any other (by card through
 * a payment plugin, or by bank transfer that an admin records); RecordInvoicePayment then puts the amount into the
 * client's balance. No tax: tax is on the services the credit later pays. A client has one open "Add funds" notice
 * at a time (a new one cancels the last), and one left unpaid past its due date is cancelled by the daily run.
 */
class AddFunds
{
    /** Days the client has to pay it before the daily run cancels it. */
    public const DAYS_TO_PAY = 14;

    public function __construct(private SendInvoiceEmails $emails) {}

    /** @throws RuntimeException with a message for the client when the amount or the client doesn't qualify */
    public function handle(Client $client, int $amountCents): Invoice
    {
        if (($why = ClientCredit::cannotAddFunds($client)) !== null) {
            throw new RuntimeException($why);
        }

        $currency = $client->currencyCode();
        $min = ClientCredit::limit('min_deposit', $client);
        $max = ClientCredit::maxDeposit($client);

        if ($amountCents < $min || $amountCents > $max) {
            throw new RuntimeException(__('Enter an amount from :min to :max.', ['min' => Money::format($min, $currency), 'max' => Money::format($max, $currency)]));
        }

        $today = CarbonImmutable::today();

        $invoice = DB::transaction(function () use ($client, $amountCents, $currency, $today) {
            foreach ($this->open($client) as $previous) {
                $this->cancel($previous, 'replaced by a new one');
            }

            $invoice = Invoice::create([
                'number' => Invoice::nextNumber($today),
                'client_id' => $client->id,
                'credit' => true,
                'status' => InvoiceStatus::Unpaid,
                'date' => $today,
                'due_date' => $today->addDays(self::DAYS_TO_PAY),
                'currency' => $currency,
                'subtotal_cents' => $amountCents,
                'tax_cents' => 0,
                'total_cents' => $amountCents,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'credit' => true,
                'description' => __('Add funds', [], $client->preferredLocale()),
                'due_date' => $today,
                'period_end' => $today,
                'amount_cents' => $amountCents,
                'tax_cents' => 0,
                'total_cents' => $amountCents,
            ]);

            ActivityLog::record('credit.notice_created', sprintf('Add funds payment notice %s for %s', $invoice->number, Money::format($amountCents, $currency)),
                client: $client, properties: ['invoice_id' => $invoice->id]);

            return $invoice;
        });

        // Payment Notice Created, with how to pay (bank details); the client is shown the notice too.
        $this->emails->handle($today, only: [$invoice->id]);

        return $invoice->refresh();
    }

    /**
     * Cancels "Add funds" notices left unpaid past their due date. Daily, with the reminders.
     *
     * @return int how many
     */
    public function cancelStale(CarbonImmutable $today, bool $dryRun = false): int
    {
        $stale = Invoice::query()->unpaid()->where('credit', true)->whereDate('due_date', '<', $today)->get();

        if (! $dryRun) {
            $stale->each(fn (Invoice $invoice) => $this->cancel($invoice, 'not paid by its due date'));
        }

        return $stale->count();
    }

    /** @return Collection<int, Invoice> */
    public function open(Client $client)
    {
        return $client->invoices()->unpaid()->where('credit', true)->get();
    }

    private function cancel(Invoice $invoice, string $why): void
    {
        $invoice->update(['status' => InvoiceStatus::Cancelled]);
        ActivityLog::record('invoice.cancelled', "Add funds payment notice {$invoice->number} cancelled: {$why}", client: $invoice->client, properties: ['invoice_id' => $invoice->id]);
    }
}
