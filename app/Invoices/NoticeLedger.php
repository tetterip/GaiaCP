<?php

namespace App\Invoices;

use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Payments\PaymentMethods;
use App\Providers\AppServiceProvider;
use App\Support\Money;

/**
 * What was paid on a payment notice, as shown on the notice's and its invoice's pages: the payments that paid its
 * lines (InvoiceItem::payment_id) and the "Add funds" amounts paid into the client's balance
 * (InvoiceItem::credit_transaction_id), oldest first. A reversed payment is deleted, so it is no longer listed.
 *
 * A notice's rows are read once and kept for the rest of the request: its page asks for them many times (Amount paid,
 * and the Payments table for every cell). Saving or deleting a notice line, a payment or a balance entry forgets them
 * (AppServiceProvider), so a payment recorded on the page shows at once.
 */
class NoticeLedger
{
    /** @var array<int, list<array<string, mixed>>> rows() by notice id */
    private static array $rows = [];

    /**
     * @return list<array{date: string, sort: string, type: string, description: string, method: string|null, reference: string|null, amount: string, amount_cents: int}>
     */
    public static function rows(Invoice $invoice): array
    {
        return self::$rows[$invoice->id] ??= self::read($invoice);
    }

    /** Read the rows again on the next rows(): a line, payment or balance entry was saved or deleted. */
    public static function forget(): void
    {
        self::$rows = [];
    }

    /** @return list<array{date: string, sort: string, type: string, description: string, method: string|null, reference: string|null, amount: string, amount_cents: int}> */
    private static function read(Invoice $invoice): array
    {
        $items = $invoice->items()->with(['payment', 'service'])->get();
        $rows = [];

        foreach ($items->whereNotNull('payment_id')->groupBy('payment_id') as $lines) {
            /** @var Payment|null $payment */
            $payment = $lines->first()->payment;

            if ($payment === null) {
                continue;
            }

            $rows[] = self::row(
                $payment->paid_on->format('Y-m-d').sprintf('%010d', $payment->id),
                $payment->paid_on->format(AppServiceProvider::dateFormat()),
                __('Payment'),
                $lines->map(fn (InvoiceItem $item) => $item->service?->label ?? $item->description)->unique()->join(', '),
                PaymentMethods::label($payment->method) ?? $payment->method,
                $payment->reference,
                $payment->amount_cents,
                $payment->currency,
            );
        }

        $deposits = CreditTransaction::query()->whereIn('id', $items->pluck('credit_transaction_id')->filter())->get();

        foreach ($deposits as $deposit) {
            $rows[] = self::row(
                $deposit->created_at->format('Y-m-d').sprintf('%010d', 0),
                $deposit->created_at->format(AppServiceProvider::dateFormat()),
                CreditType::Deposit->getLabel(),
                __('Paid into the client\'s balance'),
                PaymentMethods::label($deposit->method) ?? $deposit->method,
                $deposit->reference,
                $deposit->amount_cents,
                $deposit->currency,
            );
        }

        usort($rows, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return $rows;
    }

    /** The total of rows(): what was paid on the notice so far. */
    public static function paidCents(Invoice $invoice): int
    {
        return array_sum(array_column(self::rows($invoice), 'amount_cents'));
    }

    /** What is still owed: the lines not paid yet. Nothing on a paid or cancelled notice. */
    public static function balanceCents(Invoice $invoice): int
    {
        if ($invoice->status !== InvoiceStatus::Unpaid) {
            return 0;
        }

        return (int) $invoice->items()->unpaid()->sum('total_cents');
    }

    /** @return array{date: string, sort: string, type: string, description: string, method: string|null, reference: string|null, amount: string, amount_cents: int} */
    private static function row(string $sort, string $date, string $type, string $description, ?string $method, ?string $reference, int $cents, string $currency): array
    {
        return [
            'date' => $date,
            'sort' => $sort,
            'type' => $type,
            'description' => $description,
            'method' => $method,
            'reference' => filled($reference) ? $reference : null,
            'amount' => Money::format($cents, $currency),
            'amount_cents' => $cents,
        ];
    }
}
