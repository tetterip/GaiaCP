<?php

namespace Plugins\PayPal;

use App\Actions\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Plugins\PluginManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Payments of payment notices with PayPal (Orders v2). start() opens a PayPal order for what the notice still owes and
 * sends the client to PayPal; once the client approves it, sync() takes the money (capture), but only if the notice
 * still asks for that amount, and records the payment (RecordInvoicePayment, method "paypal", reference = the capture)
 * once PayPal says it is completed. sync() always reads the order from PayPal's API, so the client's return and the
 * webhook only say when to look, never what happened. Each order is taken and recorded once, whoever reports it first.
 */
class PayPalPayments
{
    /**
     * The currencies PayPal takes, less those it takes only in whole units (HUF, JPY, TWD) since amounts here are in
     * hundredths. A notice in any other currency is not offered "Pay with PayPal".
     */
    public const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'ILS', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'USD'];

    public function __construct(private PayPalApi $paypal, private RecordInvoicePayment $payments) {}

    /** What paying the notice now records: its unpaid lines, with the late fees. */
    public function amountDue(Invoice $invoice): int
    {
        return (int) $this->payments->unpaid($invoice)->sum('total_cents');
    }

    /** Whether the client can pay it with PayPal now: PayPal set up, the notice unpaid and every line still due. */
    public function canPay(Invoice $invoice): bool
    {
        if (! app(PluginManager::class)->enabled('paypal') || ! PayPalApi::configured() || $invoice->status !== InvoiceStatus::Unpaid
            || ! in_array(strtoupper($invoice->currency), self::CURRENCIES, true)) {
            return false;
        }

        $items = $this->payments->unpaid($invoice);

        return $items->sum('total_cents') > 0 && $this->payments->stale($items)->isEmpty();
    }

    /**
     * Opens a PayPal order for the notice.
     *
     * @return string PayPal's page where the client approves the payment
     *
     * @throws PayPalException
     */
    public function start(Invoice $invoice): string
    {
        if (! $this->canPay($invoice)) {
            throw new PayPalException(__('This payment notice can no longer be paid with PayPal.'));
        }

        $invoice->loadMissing('client');
        $amount = $this->amountDue($invoice);
        $locale = $invoice->client->preferredLocale();
        $description = __('Payment notice :number', ['number' => $invoice->number], $locale);

        $order = $this->paypal->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'notice-'.$invoice->id,
                'custom_id' => (string) $invoice->id,
                'description' => mb_substr($description, 0, 127),
                'amount' => ['currency_code' => strtoupper($invoice->currency), 'value' => self::value($amount)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => array_filter([
                'brand_name' => mb_substr((string) config('gaiacp.company.name'), 0, 127) ?: null,
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                // PayPal adds ?token=<order id>&PayerID=… to it.
                'return_url' => route('filament.client.paypal.return'),
                'cancel_url' => PaymentNoticeResource::getUrl('view', ['record' => $invoice], panel: 'client'),
            ])]],
        ], (string) Str::uuid());

        $approve = collect($order['links'] ?? [])->first(fn ($link) => in_array($link['rel'] ?? null, ['payer-action', 'approve'], true))['href'] ?? null;

        if (blank($order['id'] ?? null) || blank($approve)) {
            throw new PayPalException(__('PayPal did not return a payment page.'));
        }

        PayPalOrder::create([
            'invoice_id' => $invoice->id,
            'order_id' => $order['id'],
            'live' => PayPalApi::mode() === 'live',
            'amount_cents' => $amount,
            'currency' => strtoupper($invoice->currency),
        ]);

        ActivityLog::record('paypal.order_started', sprintf('PayPal payment of %s started for payment notice %s', Money::format($amount, $invoice->currency), $invoice->number), client: $invoice->client, properties: ['order_id' => $order['id']]);

        return (string) $approve;
    }

    /**
     * Reads the order from PayPal and acts on it: takes the money of an approved one, records a completed one, closes a
     * voided one. An order GaiaCP is done with is left as it is.
     *
     * @throws PayPalException PayPal could not be read (the order stays as it was; a later look tries again)
     */
    public function sync(PayPalOrder $order): PayPalOrder
    {
        if (! in_array($order->status, [PayPalOrder::OPEN, PayPalOrder::PENDING], true)) {
            return $order;
        }

        $remote = $this->paypal->order($order->order_id);

        return match ($remote['status'] ?? null) {
            'APPROVED' => $this->capture($order),
            'COMPLETED' => $this->settle($order, $remote),
            'VOIDED' => $this->close($order, PayPalOrder::FAILED, 'PayPal voided the order'),
            default => $order, // the client has not approved it (yet)
        };
    }

    /**
     * Payments whose taking or recording was cut off part way (the request timed out or PHP stopped): left processing,
     * an admin would never hear of them, so they are handed over like any other unmatched payment. Hourly.
     */
    public function flagStuck(): int
    {
        $stuck = PayPalOrder::query()->where('status', PayPalOrder::PROCESSING)->where('updated_at', '<', now()->subHour())->get();

        $stuck->each(fn (PayPalOrder $order) => $this->unmatched($order, 'Taking or recording the payment stopped part way; check the order at PayPal and which lines of the notice are paid'));

        return $stuck->count();
    }

    /** An amount in hundredths as PayPal writes it ("60.00"), with no floats on the way. */
    public static function value(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** PayPal's "60.00" in hundredths; null when it is not a plain amount with at most two decimals. */
    public static function cents(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** Takes the money of an approved order, once, if its notice still asks for that amount. */
    private function capture(PayPalOrder $order): PayPalOrder
    {
        // Whoever turns it from open to processing takes the money; a second report finds it taken.
        if (! $this->claim($order, [PayPalOrder::OPEN])) {
            return $order->fresh();
        }

        $problem = $this->problem($order);

        if ($problem !== null) {
            // Nothing taken: PayPal releases the approval on its own.
            $order->update(['status' => PayPalOrder::CANCELLED, 'error' => mb_substr($problem, 0, 255)]);
            ActivityLog::record('paypal.cancelled', sprintf('PayPal payment for payment notice %s was not taken: %s', $order->invoice?->number ?? '#'.$order->invoice_id, $problem), client: $order->invoice?->client, properties: ['order_id' => $order->order_id]);

            return $order;
        }

        try {
            $remote = $this->paypal->capture($order->order_id);
        } catch (PayPalException $e) {
            // Taking it again later is safe: the capture call carries the same request id.
            $order->update(['status' => PayPalOrder::OPEN, 'error' => mb_substr($e->getMessage(), 0, 255)]);

            throw $e;
        }

        return $this->settle($order, $remote, claimed: true);
    }

    /** Records an order PayPal has taken the money of, once; one still clearing waits as pending. */
    private function settle(PayPalOrder $order, array $remote, bool $claimed = false): PayPalOrder
    {
        if (! $claimed && ! $this->claim($order, [PayPalOrder::OPEN, PayPalOrder::PENDING])) {
            return $order->fresh();
        }

        $capture = (array) data_get($remote, 'purchase_units.0.payments.captures.0', []);
        $captureId = $capture['id'] ?? null;

        if (($capture['status'] ?? null) === 'PENDING') {
            $order->update(['status' => PayPalOrder::PENDING, 'capture_id' => $captureId]);

            return $order;
        }

        if (($capture['status'] ?? null) !== 'COMPLETED') {
            return $this->close($order, PayPalOrder::FAILED, 'PayPal did not complete the payment ('.($capture['status'] ?? 'no capture').')', from: PayPalOrder::PROCESSING);
        }

        $order->update(['capture_id' => $captureId]);
        $invoice = $order->invoice;

        try {
            $problem = $this->problem($order, $capture);

            if ($problem !== null) {
                return $this->unmatched($order, $problem);
            }

            $this->payments->handle($invoice, CarbonImmutable::now(), 'paypal', $captureId ?: $order->order_id);
        } catch (Throwable $e) {
            // Some lines may be recorded already: never try again on our own, an admin looks at it.
            $this->unmatched($order, $e->getMessage());

            if (! $e instanceof RuntimeException) {
                throw $e;
            }

            return $order;
        }

        $order->update(['status' => PayPalOrder::PAID, 'paid_at' => now(), 'error' => null]);

        ActivityLog::record('paypal.paid', sprintf('PayPal payment of %s received for payment notice %s', Money::format($order->amount_cents, $order->currency), $invoice->number), client: $invoice->client, properties: ['order_id' => $order->order_id, 'capture_id' => $captureId]);

        return $order;
    }

    /** @param  list<string>  $from */
    private function claim(PayPalOrder $order, array $from): bool
    {
        $claimed = PayPalOrder::query()->whereKey($order->id)->whereIn('status', $from)
            ->update(['status' => PayPalOrder::PROCESSING, 'updated_at' => now()]);

        $order->refresh();

        return $claimed > 0;
    }

    private function close(PayPalOrder $order, string $status, string $why, string $from = PayPalOrder::OPEN): PayPalOrder
    {
        PayPalOrder::query()->whereKey($order->id)->whereIn('status', array_unique([$from, PayPalOrder::OPEN, PayPalOrder::PENDING]))
            ->update(['status' => $status, 'error' => mb_substr($why, 0, 255), 'updated_at' => now()]);

        return $order->refresh();
    }

    /**
     * Why the order can't be taken or recorded on its notice as it is now; null when it can.
     *
     * @param  array<string, mixed>|null  $capture  PayPal's capture, once the money is taken
     */
    private function problem(PayPalOrder $order, ?array $capture = null): ?string
    {
        if ($capture !== null && (self::cents(data_get($capture, 'amount.value')) !== $order->amount_cents
            || strtoupper((string) data_get($capture, 'amount.currency_code')) !== $order->currency)) {
            return 'PayPal took a different amount than the order asked for';
        }

        $invoice = $order->invoice;

        if ($invoice === null || $invoice->status !== InvoiceStatus::Unpaid) {
            return 'The payment notice is no longer unpaid';
        }

        $items = $this->payments->unpaid($invoice);

        if ((int) $items->sum('total_cents') !== $order->amount_cents) {
            return 'The payment notice now asks for a different amount';
        }

        if ($this->payments->stale($items)->isNotEmpty()) {
            return 'Lines of the payment notice no longer match their service\'s due date';
        }

        return null;
    }

    private function unmatched(PayPalOrder $order, string $why): PayPalOrder
    {
        $order->update(['status' => PayPalOrder::UNMATCHED, 'error' => mb_substr($why, 0, 255)]);
        $invoice = $order->invoice;

        ActivityLog::record('payment.online_unmatched', sprintf(
            'PayPal payment of %s for payment notice %s was taken by PayPal but not recorded: %s. Record it by hand or refund it in PayPal.',
            Money::format($order->amount_cents, $order->currency),
            $invoice?->number ?? '#'.$order->invoice_id,
            $why,
        ), client: $invoice?->client, properties: ['invoice_id' => $order->invoice_id, 'invoice_number' => $invoice?->number, 'reason' => $why, 'order_id' => $order->order_id, 'capture_id' => $order->capture_id, 'amount_cents' => $order->amount_cents]);

        return $order;
    }
}
