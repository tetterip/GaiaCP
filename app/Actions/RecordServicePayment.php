<?php

namespace App\Actions;

use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Invoices\RepriceIssuedNotice;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Support\Money;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * A payment typed in by hand for a service's next cycle (the service's Record payment, POST /api/v1/payments), checked
 * against what its payment notice asks for that cycle (the line with tax, plus its late fees: expected()). When the
 * amount differs, $difference says what happens:
 *
 * - CREDIT, less: the amount goes into the client's balance and the cycle stays owed; the balance then pays the notice
 *   once it covers it. More: the cycle is paid at the notice's amount and the rest goes into the balance.
 * - DISCOUNT, less only: a "Discount" line (fee = discount) takes the difference off the notice, so the cycle is paid,
 *   its notice and invoice come to what was paid, and the service's price stays as it is. Reversing the payment takes
 *   the line off again.
 * - KEEP, more only: one payment of the whole amount, as before this check.
 *
 * Without a notice line for the cycle (an early payment) the amount is recorded as it is.
 */
class RecordServicePayment
{
    public const CREDIT = 'credit';

    public const DISCOUNT = 'discount';

    public const KEEP = 'keep';

    /** The payment recorded, null when the whole amount went into the client's balance. */
    public ?Payment $payment = null;

    /** What went into the client's balance, in cents. */
    public int $credited = 0;

    /** @param  RecordPayment  $recorder  read its renewalError and setupError after handle() */
    public function __construct(public readonly RecordPayment $recorder, private ClientCredit $credit) {}

    /** What the notice asks for the service's next cycle: its line with tax plus that cycle's late fees; null without a line. */
    public static function expected(Service $service): ?int
    {
        $lines = self::lines($service);

        return $lines->isEmpty() ? null : (int) $lines->sum('total_cents');
    }

    /** What to suggest: the notice's amount, or else what a notice would ask (the first payment until paid), with tax. */
    public static function suggested(Service $service): int
    {
        if (($expected = self::expected($service)) !== null) {
            return $expected;
        }

        $rule = $service->client ? Tax::ruleFor($service->client) : null;

        return Tax::apply(RepriceIssuedNotice::amountFor($service), $rule['rate'] ?? null, Tax::inclusive())['total'];
    }

    /**
     * What can be done with a difference of $amount from expected(): option => label; empty when it does not differ.
     *
     * @return array<string, string>
     */
    public static function options(Service $service, int $amount): array
    {
        $expected = self::expected($service);

        if ($expected === null || $amount === $expected) {
            return [];
        }

        $credit = self::canCredit($service);

        return $amount < $expected
            ? array_filter([
                self::CREDIT => $credit ? __('Put it in the client\'s balance; the notice still asks for the cycle') : null,
                self::DISCOUNT => __('Give the difference as a discount on this cycle'),
            ])
            : array_filter([
                self::CREDIT => $credit ? __('Pay the cycle and put the rest in the client\'s balance') : null,
                self::KEEP => __('Record the whole amount for the cycle'),
            ]);
    }

    /** The option a difference starts on: credit when the client has a balance to use, else a discount or the whole amount. */
    public static function defaultOption(Service $service, int $amount): ?string
    {
        return array_key_first(self::options($service, $amount));
    }

    public function handle(
        Service $service,
        int $amountCents,
        CarbonImmutable $paidOn,
        string $method,
        ?string $reference = null,
        ?string $notes = null,
        ?string $difference = null,
    ): ?Payment {
        $this->payment = null;
        $this->credited = 0;

        $expected = self::expected($service);
        $options = self::options($service, $amountCents);

        if ($options === []) {
            return $this->payment = $this->recorder->handle($service, $amountCents, $paidOn, $method, $reference, $notes, $expected !== null ? $service->next_due_date : null);
        }

        $difference ??= self::defaultOption($service, $amountCents);

        if (! array_key_exists((string) $difference, $options)) {
            throw new InvalidArgumentException("The amount differs from the payment notice's; {$difference} is not one of: ".implode(', ', array_keys($options)).'.');
        }

        $notice = self::lines($service)->first()->invoice;
        $links = ['invoice_id' => $notice->id, 'method' => $method, 'reference' => $reference];

        if ($difference === self::CREDIT && $amountCents < $expected) {
            $this->credit->record($service->client, CreditType::Deposit, $amountCents, "Payment for {$service->label}, payment notice {$notice->number}", $links);
            $this->credited = $amountCents;

            // A client paying in parts: once the balance covers the cycle, it pays it (this cycle, not the notice's others).
            if ((int) $service->client->fresh()->credit_cents >= $expected) {
                $this->payment = $this->fromBalance($service, $notice, $expected, $paidOn);
            }

            return $this->payment;
        }

        if ($difference === self::CREDIT) {
            $this->payment = $this->recorder->handle($service, $expected, $paidOn, $method, $reference, $notes, $service->next_due_date);
            $this->credit->record($service->client, CreditType::Deposit, $amountCents - $expected, "Paid more than payment notice {$notice->number} asked: {$service->label}", $links + ['payment_id' => $this->payment->id]);
            $this->credited = $amountCents - $expected;

            return $this->payment;
        }

        if ($difference === self::KEEP) {
            return $this->payment = $this->recorder->handle($service, $amountCents, $paidOn, $method, $reference, $notes, $service->next_due_date);
        }

        $discount = $this->addDiscount($service, $notice, $expected - $amountCents);

        try {
            return $this->payment = $this->recorder->handle($service, $amountCents, $paidOn, $method, $reference, $notes, $service->next_due_date);
        } catch (Throwable $e) {
            self::removeLines(new Collection([$discount]));

            throw $e;
        }
    }

    /**
     * Takes the discount lines a payment made off their notices again: called as the payment is reversed or undone.
     */
    public static function dropDiscounts(Payment $payment): void
    {
        self::removeLines(InvoiceItem::query()->where('payment_id', $payment->id)->where('fee', InvoiceItem::DISCOUNT)->with('invoice')->get());
    }

    /** Pays the cycle from the client's balance, as App\Credit\ApplyCredit does; the balance comes back if it fails. */
    private function fromBalance(Service $service, Invoice $notice, int $cents, CarbonImmutable $paidOn): ?Payment
    {
        $debit = $this->credit->record($service->client, CreditType::Applied, -$cents, "{$service->label}, payment notice {$notice->number}", ['invoice_id' => $notice->id]);

        try {
            $payment = $this->recorder->handle($service, $cents, $paidOn, ClientCredit::METHOD, notes: "Payment notice {$notice->number}", forDueDate: $service->next_due_date);
        } catch (Throwable $e) {
            $this->credit->record($service->client, CreditType::Refund, $cents, "Payment failed: {$service->label}, payment notice {$notice->number}", ['invoice_id' => $notice->id]);

            if ($e instanceof AlreadyPaid) {
                return null;
            }

            throw $e;
        }

        $debit->update(['payment_id' => $payment->id]);

        return $payment;
    }

    /** Whether the difference can go into the client's balance: credit is on and the service bills in the client's currency. */
    private static function canCredit(Service $service): bool
    {
        return ClientCredit::enabled() && $service->client !== null
            && strtoupper((string) $service->currency) === $service->client->currencyCode();
    }

    /** @return Collection<int, InvoiceItem> the unpaid lines of the service's next cycle, with its late fees */
    private static function lines(Service $service): Collection
    {
        return InvoiceItem::query()->where('service_id', $service->id)->forCycle()
            ->whereOnDate('due_date', $service->next_due_date)->whereNull('payment_id')
            ->whereHas('invoice', fn ($q) => $q->unpaid())->with('invoice')->orderBy('id')->get();
    }

    /** A line of minus $cents (with tax) for the cycle, on its notice. */
    private function addDiscount(Service $service, Invoice $notice, int $cents): InvoiceItem
    {
        return DB::transaction(function () use ($service, $notice, $cents) {
            $net = $notice->tax_rate ? (int) round($cents / (1 + $notice->tax_rate / 100)) : $cents;
            $line = self::lines($service)->firstWhere('fee', null) ?? self::lines($service)->first();

            $discount = $notice->items()->create([
                'service_id' => $service->id,
                'fee' => InvoiceItem::DISCOUNT,
                'description' => mb_substr(__('Discount').': '.$service->label, 0, 255),
                'due_date' => $line->due_date,
                'period_end' => $line->period_end,
                'amount_cents' => -($notice->tax_inclusive ? $cents : $net),
                'tax_cents' => -($cents - $net),
                'total_cents' => -$cents,
            ]);

            self::retotal($notice);

            ActivityLog::record('invoice.discount_added', sprintf(
                'Discount of %s added to payment notice %s: paid less than it asked',
                Money::format($cents, $notice->currency),
                $notice->number,
            ), $service, properties: ['invoice_id' => $notice->id]);

            return $discount;
        });
    }

    /** @param  Collection<int, InvoiceItem>  $lines */
    private static function removeLines(Collection $lines): void
    {
        foreach ($lines->groupBy('invoice_id') as $group) {
            $notice = $group->first()->invoice;
            InvoiceItem::query()->whereKey($group->modelKeys())->delete();
            self::retotal($notice);

            ActivityLog::record('invoice.discount_removed', "Discount taken off payment notice {$notice->number}", client: $notice->client, properties: ['invoice_id' => $notice->id]);
        }
    }

    private static function retotal(Invoice $notice): void
    {
        $items = $notice->items()->get();

        $notice->update([
            'subtotal_cents' => $items->sum('total_cents') - $items->sum('tax_cents'),
            'tax_cents' => $items->sum('tax_cents'),
            'total_cents' => $items->sum('total_cents'),
        ]);
    }
}
