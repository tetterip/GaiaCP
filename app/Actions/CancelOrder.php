<?php

namespace App\Actions;

use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Invoicing\IssueCreditNote;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Support\Currencies;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cancels an order that nothing was set up for yet: the order, its items and its unpaid payment notice. Used by the
 * order page's "Cancel order", the API and gaiacp:cancel-unpaid-orders (Settings → Automation → Cancel unpaid orders
 * after). What its items took of their products' Quantity available goes back (PlaceOrder).
 *
 * An order can be paid and still pending (set up on Complete order, or an automatic set up that failed). Its payments
 * are then dealt with as $payments says: REFUND_TO_CREDIT and REFUNDED_ELSEWHERE undo them (the notice owes the lines
 * again and is cancelled with the order, a credit note undoes an issued invoice's lines of these items). The payment
 * stays on the day it was made and a refund of minus its amount is recorded today (`refund_of`, as RefundPayment
 * does), so the income of the month it was paid in never changes afterwards;
 * REFUND_TO_CREDIT puts the money in the client's balance, REFUNDED_ELSEWHERE records that it was paid back outside
 * GaiaCP (nothing is sent to Stripe or a bank). A payment made from credit always goes back to credit. KEEP leaves
 * them as they were.
 */
class CancelOrder
{
    public const REFUND_TO_CREDIT = 'credit';

    public const REFUNDED_ELSEWHERE = 'refunded';

    public const KEEP = 'keep';

    public const PAYMENT_OPTIONS = [self::REFUND_TO_CREDIT, self::REFUNDED_ELSEWHERE, self::KEEP];

    public function __construct(private ClientCredit $credit, private IssueCreditNote $creditNotes) {}

    /** Pending, with every item still pending (an active one has to be terminated on its own). */
    public static function canCancel(Order $order): bool
    {
        return $order->status === OrderStatus::Pending
            && ! $order->services()->where('status', '!=', ServiceStatus::Pending)->exists();
    }

    /** Whether any of its items has a payment recorded: such an order is never cancelled automatically. */
    public static function hasPayments(Order $order): bool
    {
        return self::payments($order)->exists();
    }

    /** @return Builder<Payment> */
    public static function payments(Order $order)
    {
        // Not a payment given back already (RefundPayment), nor its refund
        return Payment::query()->whereIn('service_id', $order->services()->select('id'))->whereNull('refund_of')->whereDoesntHave('refunds');
    }

    /** What was paid for the order's items, per currency, e.g. "€120.00". */
    public static function paidTotal(Order $order): string
    {
        return self::payments($order)->get()->groupBy('currency')
            ->map(fn (Collection $payments, string $currency) => Money::format((int) $payments->sum('amount_cents'), $currency))
            ->implode(' + ');
    }

    /** The default for $payments: back to credit when the client has a balance to use, else refunded elsewhere. */
    public static function defaultPaymentOption(): string
    {
        return ClientCredit::enabled() ? self::REFUND_TO_CREDIT : self::REFUNDED_ELSEWHERE;
    }

    /** @param  string|null  $payments  one of PAYMENT_OPTIONS; null for defaultPaymentOption() */
    public function handle(Order $order, ?string $why = null, ?string $payments = null): void
    {
        $payments ??= self::defaultPaymentOption();

        if (! in_array($payments, self::PAYMENT_OPTIONS, true)) {
            throw new InvalidArgumentException("Unknown payment option {$payments}.");
        }

        $returned = DB::transaction(function () use ($order, $payments) {
            // The order row is the lock: two cancels at once never undo the same payments twice.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! self::canCancel($order)) {
                return null;
            }

            $returned = ['credit' => 0, 'elsewhere' => 0];

            if ($payments !== self::KEEP) {
                foreach (self::payments($order)->with('service')->orderBy('id')->get() as $payment) {
                    $returned[$this->undo($payment, $order, $payments)] += 1;
                }
            }

            $order->services()->whereNotNull('product_id')->pluck('product_id')->countBy()
                ->each(fn (int $count, int $product) => Product::query()->whereKey($product)->whereNotNull('stock')->increment('stock', $count));
            $order->services()->update(['status' => ServiceStatus::Cancelled]);
            $order->update(['status' => OrderStatus::Cancelled]);
            // A notice paid in part (payments kept) is closed for what was paid, so that part keeps its invoice
            $order->invoices()->unpaid()->get()->each(fn (Invoice $invoice) => $invoice->cancel("order #{$order->id} cancelled"));

            return $returned;
        });

        if ($returned === null) {
            return;
        }

        $order->refresh();

        $message = "Order #{$order->id} cancelled".($why ? ": {$why}" : '');
        $message .= match (true) {
            $returned['credit'] > 0 && $returned['elsewhere'] > 0 => '; payments returned to credit and marked refunded',
            $returned['credit'] > 0 => '; payments returned to credit',
            $returned['elsewhere'] > 0 => '; payments marked refunded outside GaiaCP',
            $payments === self::KEEP && self::hasPayments($order) => '; payments kept',
            default => '',
        };

        ActivityLog::record($why ? 'order.auto_cancelled' : 'order.cancelled', $message, client: $order->client, properties: ['order_id' => $order->id, 'payments' => $payments]);
    }

    /** Undoes one payment of the order; returns where the money went: 'credit' or 'elsewhere'. */
    private function undo(Payment $payment, Order $order, string $option): string
    {
        $service = $payment->service;
        $service->update(['next_due_date' => $payment->due_date_before]);

        // Its notice owes the line again (and is cancelled with the order); an issued invoice gets a credit note
        // for the lines of the order's items. A discount it was given comes off with it.
        RecordServicePayment::dropDiscounts($payment);

        foreach (InvoiceItem::query()->where('payment_id', $payment->id)->with('invoice.taxInvoice')->get() as $item) {
            $item->update(['payment_id' => null]);
            $invoice = $item->invoice;

            if ($invoice->status !== InvoiceStatus::Paid) {
                continue;
            }

            $invoice->update(['status' => InvoiceStatus::Unpaid, 'paid_on' => null]);

            if ($taxInvoice = $invoice->taxInvoice) {
                $lines = $taxInvoice->creditableItems()->whereIn('service_id', $order->services()->pluck('id')->all());

                if ($lines->isNotEmpty()) {
                    $this->creditNotes->handle($taxInvoice, $lines->pluck('id')->all(), IssueCreditNote::ORDER_CANCELLED);
                }
            }
        }

        $toCredit = $option === self::REFUND_TO_CREDIT || $payment->method === ClientCredit::METHOD;
        $client = $payment->client ?? $order->client;

        // Kept on its day, with its refund today: what was taken in a past month stays in that month's income.
        $refund = Payment::create([
            'service_id' => $service->id,
            'client_id' => $payment->client_id,
            'refund_of' => $payment->id,
            'amount_cents' => -$payment->amount_cents,
            'currency' => $payment->currency,
            'paid_on' => CarbonImmutable::today(),
            'method' => $toCredit ? ClientCredit::METHOD : $payment->method,
            'reference' => $payment->reference,
            'due_date_before' => $service->next_due_date,
            'due_date_after' => $service->next_due_date,
            'recorded_by' => ApiKey::actingUserId(),
            'notes' => "Order #{$order->id} cancelled",
        ]);

        if ($toCredit && $client) {
            $this->credit->record($client, CreditType::Refund, self::inClientCurrency($payment, $client), "Order #{$order->id} cancelled: {$service->label}", ['payment_id' => $refund->id]);
        }

        ActivityLog::record('payment.reversed', sprintf(
            'Payment of %s of %s refunded, order #%d cancelled; %s',
            Money::format($payment->amount_cents, $payment->currency),
            $payment->paid_on->toDateString(),
            $order->id,
            $toCredit ? 'returned to credit' : 'refunded outside GaiaCP',
        ), $service, properties: ['payment_id' => $payment->id, 'refund_id' => $refund->id, 'order_id' => $order->id]);

        return $toCredit ? 'credit' : 'elsewhere';
    }

    private static function inClientCurrency(Payment $payment, Client $client): int
    {
        $currency = $client->currencyCode();

        return $payment->currency === $currency
            ? (int) $payment->amount_cents
            : Currencies::fromBase(Currencies::toBase((int) $payment->amount_cents, $payment->currency), $currency);
    }
}
