<?php

namespace Plugins\NexiXpay;

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
 * Card payments of payment notices through Nexi XPay (the NPG API's hosted payment page). start() opens a payment for
 * what the notice still owes and sends the client to Nexi's page. sync() always reads the order back from Nexi's API
 * with the merchant's own key, so the client's return and Nexi's notification only say when to look, never what
 * happened; an authorised payment of the amount asked for is recorded (RecordInvoicePayment, method "nexixpay",
 * reference = the order ID) once, whoever reports it first. recheck() looks up every hour the payments still waiting.
 */
class NexiXpayPayments
{
    /**
     * The currencies Nexi takes with two decimals (its WC_Gateway_NPG_Currency list), since amounts here are in
     * hundredths. A notice in any other currency is not offered "Pay by card (Nexi)".
     */
    public const CURRENCIES = ['AED', 'AOA', 'ARS', 'AUD', 'AZN', 'BAM', 'BGN', 'BRL', 'BYN', 'CAD', 'CHF', 'CNY', 'COP', 'CZK', 'DKK', 'EGP', 'EUR', 'GBP', 'GIP', 'HKD', 'HUF', 'INR', 'KZT', 'MKD', 'MXN', 'MYR', 'NGN', 'NOK', 'PHP', 'PLN', 'QAR', 'RON', 'RSD', 'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'TWD', 'UAH', 'USD', 'ZAR'];

    /** Nexi's operation results that mean the money is taken (AUTHORIZED: captured at once, or later by Nexi's own settings). */
    public const PAID_RESULTS = ['AUTHORIZED', 'EXECUTED'];

    public const FAILED_RESULTS = ['DECLINED', 'DENIED_BY_RISK', 'FAILED', 'THREEDS_FAILED', '3DS_FAILED'];

    public const CANCELLED_RESULTS = ['CANCELED', 'CANCELLED', 'VOIDED'];

    /** Languages of Nexi's page, by the client's language; English for the rest. */
    public const LANGUAGES = ['el' => 'ELL', 'it' => 'ITA', 'de' => 'DEU', 'fr' => 'FRA', 'es' => 'SPA', 'pt' => 'POR'];

    /** How long a payment the client has not finished is looked up, and one Nexi has no outcome for yet. */
    public const OPEN_HOURS = 24;

    public const PENDING_DAYS = 7;

    public function __construct(private NexiXpayApi $nexi, private RecordInvoicePayment $payments) {}

    /** What paying the notice now records: its unpaid lines, with the late fees. */
    public function amountDue(Invoice $invoice): int
    {
        return (int) $this->payments->unpaid($invoice)->sum('total_cents');
    }

    /** Whether the client can pay it with Nexi now: Nexi set up, the notice unpaid and every line still due. */
    public function canPay(Invoice $invoice): bool
    {
        if (! app(PluginManager::class)->enabled('nexi-xpay') || ! NexiXpayApi::configured() || $invoice->status !== InvoiceStatus::Unpaid
            || ! in_array(strtoupper($invoice->currency), self::CURRENCIES, true)) {
            return false;
        }

        $items = $this->payments->unpaid($invoice);

        return $items->sum('total_cents') > 0 && $this->payments->stale($items)->isEmpty();
    }

    /**
     * Opens a payment for the notice on Nexi's hosted page.
     *
     * @return string Nexi's page where the client pays
     *
     * @throws NexiXpayException
     */
    public function start(Invoice $invoice): string
    {
        if (! $this->canPay($invoice)) {
            throw new NexiXpayException(__('This payment notice can no longer be paid by card.'));
        }

        $invoice->loadMissing('client');
        $client = $invoice->client;
        $amount = $this->amountDue($invoice);
        $currency = strtoupper($invoice->currency);
        $locale = $client->preferredLocale();
        $orderId = self::orderId($invoice);

        $page = $this->nexi->hostedPage([
            'order' => array_filter([
                'orderId' => $orderId,
                'amount' => (string) $amount,
                'currency' => $currency,
                'description' => mb_substr(__('Payment notice :number', ['number' => $invoice->number], $locale), 0, 255),
                'customerInfo' => array_filter([
                    'cardHolderName' => mb_substr(trim($client->first_name.' '.$client->last_name), 0, 255) ?: null,
                    'cardHolderEmail' => $client->email ?: null,
                ]) ?: null,
            ]),
            'paymentSession' => [
                'actionType' => 'PAY',
                'amount' => (string) $amount,
                'language' => self::LANGUAGES[substr((string) $locale, 0, 2)] ?? 'ENG',
                'resultUrl' => route('filament.client.nexi-xpay.return', ['order' => $orderId]),
                'cancelUrl' => route('filament.client.nexi-xpay.return', ['order' => $orderId, 'cancelled' => 1]),
                'notificationUrl' => NexiXpayApi::notificationUrl(),
            ],
        ]);

        if (blank($page['hostedPage'] ?? null)) {
            throw new NexiXpayException(__('Nexi did not return a payment page.'));
        }

        NexiXpayPayment::create([
            'invoice_id' => $invoice->id,
            'order_id' => $orderId,
            'live' => NexiXpayApi::mode() === 'live',
            'amount_cents' => $amount,
            'currency' => $currency,
            'security_token' => $page['securityToken'] ?? null,
        ]);

        ActivityLog::record('nexi-xpay.started', sprintf('Nexi XPay card payment of %s started for payment notice %s', Money::format($amount, $currency), $invoice->number), client: $client, properties: ['order_id' => $orderId]);

        return (string) $page['hostedPage'];
    }

    /**
     * Reads the order from Nexi and acts on it: records an authorised payment, closes a refused or cancelled one, keeps
     * waiting on one without an outcome. A payment GaiaCP is done with is left as it is.
     *
     * @param  bool  $cancelled  the client came back through the cancel address: with no payment at Nexi, it is closed
     *
     * @throws NexiXpayException Nexi could not be read (the payment stays as it was; a later look tries again)
     */
    public function sync(NexiXpayPayment $payment, bool $cancelled = false): NexiXpayPayment
    {
        if (! in_array($payment->status, [NexiXpayPayment::OPEN, NexiXpayPayment::PENDING, NexiXpayPayment::EXPIRED], true)) {
            return $payment;
        }

        $order = $this->nexi->order($payment->order_id);
        $operations = collect($order['operations'] ?? [])->filter(fn ($op) => is_array($op) && ($op['operationType'] ?? null) === 'AUTHORIZATION');

        if ($paid = $operations->first(fn (array $op) => in_array($op['operationResult'] ?? null, self::PAID_RESULTS, true))) {
            return $this->settle($payment, $paid);
        }

        $last = $operations->last();
        $result = is_array($last) ? ($last['operationResult'] ?? null) : null;

        return match (true) {
            $result === 'PENDING' => $this->close($payment, NexiXpayPayment::PENDING, 'Nexi has no final outcome yet'),
            in_array($result, self::FAILED_RESULTS, true) => $this->close($payment, NexiXpayPayment::FAILED, 'Nexi did not accept the payment ('.$result.')'),
            in_array($result, self::CANCELLED_RESULTS, true), $cancelled && $last === null => $this->close($payment, NexiXpayPayment::CANCELLED, 'Cancelled on Nexi\'s page'),
            default => $payment, // the client has not paid (yet)
        };
    }

    /**
     * Looks up at Nexi the payments GaiaCP is still waiting on, in case the client never came back and Nexi's
     * notification never came: an open one for OPEN_HOURS (then it is closed as expired and named in the activity
     * log), a pending one for PENDING_DAYS. An expired one is still recorded if Nexi says later that it was paid.
     * Hourly.
     */
    public function recheck(): int
    {
        $waiting = NexiXpayPayment::query()
            ->where('live', NexiXpayApi::mode() === 'live') // each read with the key of its own mode only
            ->where(fn ($query) => $query
                ->where(fn ($open) => $open->where('status', NexiXpayPayment::OPEN)->where('created_at', '<', now()->subMinutes(15)))
                ->orWhere(fn ($pending) => $pending->where('status', NexiXpayPayment::PENDING)
                    ->where('created_at', '>', now()->subDays(self::PENDING_DAYS))))
            ->get();

        foreach ($waiting as $payment) {
            try {
                $payment = $this->sync($payment);
            } catch (NexiXpayException) {
                // tried again next hour
            }

            if ($payment->status === NexiXpayPayment::OPEN && $payment->created_at->lt(now()->subHours(self::OPEN_HOURS))
                && $this->close($payment, NexiXpayPayment::EXPIRED, 'Not finished on Nexi\'s page')->status === NexiXpayPayment::EXPIRED) {
                ActivityLog::record('nexi-xpay.no_answer', sprintf('Nexi XPay card payment of %s for payment notice %s was not finished: the client most likely left Nexi\'s page. If the client says they paid, look for order %s in Nexi\'s back office.', Money::format($payment->amount_cents, $payment->currency), $payment->invoice->number ?? '#'.$payment->invoice_id, $payment->order_id), client: $payment->invoice?->client, properties: ['order_id' => $payment->order_id]);
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
        $stuck = NexiXpayPayment::query()->where('status', NexiXpayPayment::PROCESSING)->where('updated_at', '<', now()->subHour())->get();

        $stuck->each(fn (NexiXpayPayment $payment) => $this->unmatched($payment, 'Recording the payment stopped part way; check which lines of the notice are paid'));

        return $stuck->count();
    }

    /** Nexi's order ID for a new payment (at most 18 characters): the notice's ID and random characters. */
    public static function orderId(Invoice $invoice): string
    {
        $prefix = $invoice->id.'G';

        return $prefix.Str::upper(Str::random(max(8, 18 - strlen($prefix))));
    }

    /**
     * Records a payment Nexi took, once.
     *
     * @param  array<string, mixed>  $operation  Nexi's authorisation
     */
    private function settle(NexiXpayPayment $payment, array $operation): NexiXpayPayment
    {
        // Whoever turns it from waiting to processing records it; a second report finds it taken.
        $claimed = NexiXpayPayment::query()->whereKey($payment->id)
            ->whereIn('status', [NexiXpayPayment::OPEN, NexiXpayPayment::PENDING, NexiXpayPayment::EXPIRED])
            ->update(['status' => NexiXpayPayment::PROCESSING, 'operation_id' => mb_substr((string) ($operation['operationId'] ?? ''), 0, 255) ?: null, 'updated_at' => now()]);
        $payment->refresh();

        if ($claimed === 0) {
            return $payment;
        }

        $invoice = $payment->invoice;

        try {
            $problem = $this->problem($payment, $operation);

            if ($problem !== null) {
                return $this->unmatched($payment, $problem);
            }

            $this->payments->handle($invoice, CarbonImmutable::now(), 'nexixpay', $payment->order_id);
        } catch (Throwable $e) {
            // Some lines may be recorded already: never try again on our own, an admin looks at it.
            $this->unmatched($payment, $e->getMessage());

            if (! $e instanceof RuntimeException) {
                throw $e;
            }

            return $payment;
        }

        $payment->update(['status' => NexiXpayPayment::PAID, 'paid_at' => now(), 'error' => null]);

        ActivityLog::record('nexi-xpay.paid', sprintf('Nexi XPay card payment of %s received for payment notice %s', Money::format($payment->amount_cents, $payment->currency), $invoice->number), client: $invoice->client, properties: ['order_id' => $payment->order_id, 'operation_id' => $payment->operation_id]);

        return $payment;
    }

    private function close(NexiXpayPayment $payment, string $status, string $why): NexiXpayPayment
    {
        NexiXpayPayment::query()->whereKey($payment->id)->whereIn('status', [NexiXpayPayment::OPEN, NexiXpayPayment::PENDING, NexiXpayPayment::EXPIRED])
            ->update(['status' => $status, 'error' => mb_substr($why, 0, 255), 'updated_at' => now()]);

        return $payment->refresh();
    }

    /**
     * Why the payment can't be recorded on its notice as it is now; null when it can.
     *
     * @param  array<string, mixed>  $operation
     */
    private function problem(NexiXpayPayment $payment, array $operation): ?string
    {
        if ((string) ($operation['operationAmount'] ?? '') !== (string) $payment->amount_cents
            || strtoupper((string) ($operation['operationCurrency'] ?? '')) !== $payment->currency) {
            return 'Nexi took a different amount than the payment asked for';
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

    private function unmatched(NexiXpayPayment $payment, string $why): NexiXpayPayment
    {
        $payment->update(['status' => NexiXpayPayment::UNMATCHED, 'error' => mb_substr($why, 0, 255)]);
        $invoice = $payment->invoice;

        ActivityLog::record('payment.online_unmatched', sprintf(
            'Nexi XPay card payment of %s for payment notice %s was taken by Nexi but not recorded: %s. Record it by hand or refund it in Nexi\'s back office.',
            Money::format($payment->amount_cents, $payment->currency),
            $invoice->number ?? '#'.$payment->invoice_id,
            $why,
        ), client: $invoice?->client, properties: ['invoice_id' => $payment->invoice_id, 'invoice_number' => $invoice?->number, 'reason' => $why, 'order_id' => $payment->order_id, 'amount_cents' => $payment->amount_cents]);

        return $payment;
    }
}
