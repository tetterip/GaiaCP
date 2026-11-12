<?php

namespace Plugins\MastercardGateway;

use App\Actions\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Plugins\PluginManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Card payments of payment notices through Mastercard Gateway's hosted checkout. start() opens a checkout session for
 * what the notice still owes; the client's browser opens the gateway's payment page (MastercardController::pay()) and
 * comes back to return(). sync() always reads the order back from the gateway's API as the merchant, so the return
 * (and its resultIndicator) only says when to look, never what happened; an order captured for the amount asked for is
 * recorded (RecordInvoicePayment, method "mastercard", reference = the order ID) once, whoever looks first. recheck()
 * looks up every hour the payments still waiting.
 */
class MastercardPayments
{
    /** Currencies with two decimals, since amounts here are in hundredths; the gateway account must take the notice's. */
    public const CURRENCIES = ['AED', 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EGP', 'EUR', 'GBP', 'HKD', 'HUF', 'INR', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'QAR', 'RON', 'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR'];

    /** How long a payment the client has not finished is looked up (the gateway's checkout session lasts less). */
    public const OPEN_HOURS = 24;

    public function __construct(private MastercardApi $gateway, private RecordInvoicePayment $payments) {}

    /** What paying the notice now records: its unpaid lines, with the late fees. */
    public function amountDue(Invoice $invoice): int
    {
        return (int) $this->payments->unpaid($invoice)->sum('total_cents');
    }

    /** Whether the client can pay it now: the gateway set up, the notice unpaid and every line still due. */
    public function canPay(Invoice $invoice): bool
    {
        if (! app(PluginManager::class)->enabled('mastercard') || ! MastercardApi::configured() || $invoice->status !== InvoiceStatus::Unpaid
            || ! in_array(strtoupper($invoice->currency), self::CURRENCIES, true)) {
            return false;
        }

        $items = $this->payments->unpaid($invoice);

        return $items->sum('total_cents') > 0 && $this->payments->stale($items)->isEmpty();
    }

    /**
     * Opens a checkout session for the notice.
     *
     * @throws MastercardException
     */
    public function start(Invoice $invoice): MastercardPayment
    {
        if (! $this->canPay($invoice)) {
            throw new MastercardException(__('This payment notice can no longer be paid by card.'));
        }

        $invoice->loadMissing('client');
        $amount = $this->amountDue($invoice);
        $currency = strtoupper($invoice->currency);
        $orderId = 'GC'.$invoice->id.'-'.Str::lower(Str::random(16));
        $locale = $invoice->client->preferredLocale();
        $back = route('filament.client.mastercard.return', ['order' => $orderId]);

        $session = $this->gateway->initiateCheckout([
            'order' => [
                'id' => $orderId,
                'reference' => $orderId,
                'amount' => self::value($amount),
                'currency' => $currency,
                'description' => mb_substr(__('Payment notice :number', ['number' => $invoice->number], $locale), 0, 127),
            ],
            'interaction' => [
                'operation' => 'PURCHASE',
                'returnUrl' => $back,
                'cancelUrl' => $back,
                'merchant' => array_filter([
                    'name' => mb_substr((string) config('gaiacp.company.name'), 0, 39) ?: mb_substr((string) config('app.name'), 0, 39),
                    'url' => url('/'),
                ]),
                'displayControl' => ['billingAddress' => 'HIDE', 'customerEmail' => 'HIDE', 'shipping' => 'HIDE'],
            ],
            'transaction' => ['reference' => $orderId, 'source' => 'INTERNET'],
        ]);

        $sessionId = data_get($session, 'session.id');

        if (! is_string($sessionId) || $sessionId === '') {
            throw new MastercardException(__('The card gateway did not open a payment page.'));
        }

        $payment = MastercardPayment::create([
            'invoice_id' => $invoice->id,
            'order_id' => $orderId,
            'live' => MastercardApi::mode() === 'live',
            'amount_cents' => $amount,
            'currency' => $currency,
            'session_id' => $sessionId,
            'success_indicator' => is_string($session['successIndicator'] ?? null) ? $session['successIndicator'] : null,
        ]);

        ActivityLog::record('mastercard.started', sprintf('Mastercard Gateway card payment of %s started for payment notice %s', Money::format($amount, $currency), $invoice->number), client: $invoice->client, properties: ['order_id' => $orderId]);

        return $payment;
    }

    /**
     * Reads the order from the gateway and acts on it: records a captured one, marks a declined one failed (it is
     * still recorded if the client then pays on the same page). A payment GaiaCP is done with is left as it is.
     *
     * @param  bool  $returned  the client came back from the gateway's page: an order that is not captured is failed
     *
     * @throws MastercardException the gateway could not be read (the payment stays as it was; a later look tries again)
     */
    public function sync(MastercardPayment $payment, bool $returned = false): MastercardPayment
    {
        if (! in_array($payment->status, [MastercardPayment::OPEN, MastercardPayment::FAILED, MastercardPayment::EXPIRED], true)) {
            return $payment;
        }

        $order = $this->gateway->order($payment->order_id);

        if ($order !== null && ($order['status'] ?? null) === 'CAPTURED' && ($order['result'] ?? null) === 'SUCCESS') {
            return $this->settle($payment, $order);
        }

        if ($returned && $order !== null && $payment->status === MastercardPayment::OPEN) {
            return $this->close($payment, MastercardPayment::FAILED, 'The gateway did not capture the payment ('.($order['status'] ?? 'no status').')');
        }

        return $payment; // not paid (yet)
    }

    /**
     * Looks up at the gateway the payments GaiaCP is still waiting on, in case the client never came back: an open
     * one for OPEN_HOURS (then it is closed as expired and named in the activity log). Hourly.
     */
    public function recheck(): int
    {
        $waiting = MastercardPayment::query()
            ->where('live', MastercardApi::mode() === 'live') // each read as the merchant of its own mode only
            ->where('status', MastercardPayment::OPEN)
            ->where('created_at', '<', now()->subMinutes(15))
            ->get();

        foreach ($waiting as $payment) {
            try {
                $payment = $this->sync($payment);
            } catch (MastercardException) {
                // tried again next hour
            }

            if ($payment->status === MastercardPayment::OPEN && $payment->created_at->lt(now()->subHours(self::OPEN_HOURS))
                && $this->close($payment, MastercardPayment::EXPIRED, 'Not finished on the gateway\'s page')->status === MastercardPayment::EXPIRED) {
                ActivityLog::record('mastercard.no_answer', sprintf('Mastercard Gateway card payment of %s for payment notice %s was not finished: the client most likely left the payment page. If the client says they paid, look for order %s in the gateway\'s Merchant Administration.', Money::format($payment->amount_cents, $payment->currency), $payment->invoice->number ?? '#'.$payment->invoice_id, $payment->order_id), client: $payment->invoice?->client, properties: ['order_id' => $payment->order_id]);
            }
        }

        return $waiting->count();
    }

    /**
     * Payments whose recording was cut off part way (PHP stopped): left processing, an admin would never hear of them,
     * so they are handed over like any other unmatched payment. Hourly.
     */
    public function flagStuck(): int
    {
        $stuck = MastercardPayment::query()->where('status', MastercardPayment::PROCESSING)->where('updated_at', '<', now()->subHour())->get();

        $stuck->each(fn (MastercardPayment $payment) => $this->unmatched($payment, 'Recording the payment stopped part way; check which lines of the notice are paid'));

        return $stuck->count();
    }

    /** An amount in hundredths as the gateway writes it ("60.00"), with no floats on the way. */
    public static function value(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The gateway's 60 or 60.0 or "60.00" in hundredths; null when it is not a plain amount with at most two decimals. */
    public static function cents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }

        if (! is_string($value) || ! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * Records an order the gateway captured, once.
     *
     * @param  array<string, mixed>  $order
     */
    private function settle(MastercardPayment $payment, array $order): MastercardPayment
    {
        $transaction = collect($order['transaction'] ?? [])->last(fn ($txn) => is_array($txn) && ($txn['result'] ?? null) === 'SUCCESS'
            && in_array(data_get($txn, 'transaction.type'), ['PAYMENT', 'CAPTURE'], true));

        // Whoever turns it from waiting to processing records it; a second look finds it taken.
        $claimed = MastercardPayment::query()->whereKey($payment->id)
            ->whereIn('status', [MastercardPayment::OPEN, MastercardPayment::FAILED, MastercardPayment::EXPIRED])
            ->update(['status' => MastercardPayment::PROCESSING, 'transaction_id' => mb_substr((string) data_get($transaction, 'transaction.id', ''), 0, 255) ?: null, 'updated_at' => now()]);
        $payment->refresh();

        if ($claimed === 0) {
            return $payment;
        }

        $invoice = $payment->invoice;

        try {
            $problem = $this->problem($payment, $order);

            if ($problem !== null) {
                return $this->unmatched($payment, $problem);
            }

            $this->payments->handle($invoice, CarbonImmutable::now(), 'mastercard', $payment->order_id);
        } catch (Throwable $e) {
            // Some lines may be recorded already: never try again on our own, an admin looks at it.
            $this->unmatched($payment, $e->getMessage());

            if (! $e instanceof RuntimeException) {
                throw $e;
            }

            return $payment;
        }

        $payment->update(['status' => MastercardPayment::PAID, 'paid_at' => now(), 'error' => null]);

        ActivityLog::record('mastercard.paid', sprintf('Mastercard Gateway card payment of %s received for payment notice %s', Money::format($payment->amount_cents, $payment->currency), $invoice->number), client: $invoice->client, properties: ['order_id' => $payment->order_id, 'transaction_id' => $payment->transaction_id]);

        return $payment;
    }

    private function close(MastercardPayment $payment, string $status, string $why): MastercardPayment
    {
        MastercardPayment::query()->whereKey($payment->id)->where('status', MastercardPayment::OPEN)
            ->update(['status' => $status, 'error' => mb_substr($why, 0, 255), 'updated_at' => now()]);

        return $payment->refresh();
    }

    /**
     * Why the payment can't be recorded on its notice as it is now; null when it can.
     *
     * @param  array<string, mixed>  $order
     */
    private function problem(MastercardPayment $payment, array $order): ?string
    {
        if (self::cents($order['totalCapturedAmount'] ?? null) !== $payment->amount_cents
            || strtoupper((string) ($order['currency'] ?? '')) !== $payment->currency) {
            return 'The gateway captured a different amount than the payment asked for';
        }

        $invoice = $payment->invoice;

        if ($invoice === null || $invoice->status !== InvoiceStatus::Unpaid) {
            return 'The payment notice is no longer unpaid';
        }

        $items = $this->payments->unpaid($invoice);

        if ((int) $items->sum('total_cents') !== $payment->amount_cents) {
            return 'The payment notice now asks for a different amount';
        }

        if ($this->payments->stale($items)->isNotEmpty()) {
            return 'Lines of the payment notice no longer match their service\'s due date';
        }

        return null;
    }

    private function unmatched(MastercardPayment $payment, string $why): MastercardPayment
    {
        $payment->update(['status' => MastercardPayment::UNMATCHED, 'error' => mb_substr($why, 0, 255)]);
        $invoice = $payment->invoice;

        ActivityLog::record('payment.online_unmatched', sprintf(
            'Mastercard Gateway card payment of %s for payment notice %s was taken by the gateway but not recorded: %s. Record it by hand or refund it in the gateway\'s Merchant Administration.',
            Money::format($payment->amount_cents, $payment->currency),
            $invoice->number ?? '#'.$payment->invoice_id,
            $why,
        ), client: $invoice?->client, properties: ['invoice_id' => $payment->invoice_id, 'invoice_number' => $invoice?->number, 'reason' => $why, 'order_id' => $payment->order_id, 'amount_cents' => $payment->amount_cents]);

        return $payment;
    }
}
