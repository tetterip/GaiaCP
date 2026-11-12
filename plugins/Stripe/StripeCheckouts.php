<?php

namespace Plugins\Stripe;

use App\Actions\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Filament\Client\Resources\PaymentNotices\PaymentNoticeResource;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Plugins\PluginManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Card payments of payment notices through Stripe Checkout. start() opens a Stripe-hosted payment page for what the
 * notice still owes; complete() records the payment on the notice (RecordInvoicePayment, method "stripe", reference =
 * the PaymentIntent) once Stripe says the session is paid, from the signed webhook or from Stripe's API when the
 * client comes back. Each session is recorded once, whoever reports it first.
 */
class StripeCheckouts
{
    /** Just over Stripe's shortest (30): a page left open longer can't be paid, so the amount can't go stale for long. */
    public const EXPIRES_MINUTES = 35;

    public function __construct(private StripeApi $stripe, private RecordInvoicePayment $payments) {}

    /** What paying the notice now records: its unpaid lines, with the late fees. */
    public function amountDue(Invoice $invoice): int
    {
        return (int) $this->payments->unpaid($invoice)->sum('total_cents');
    }

    /**
     * Stripe takes these in whole units, not hundredths, while amounts here are always in hundredths, so a notice in
     * one of them is not offered for card payment.
     */
    public const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Stripe takes these in thousandths (5.120 KWD = 5120), so an amount in hundredths is sent ten times larger. */
    public const THREE_DECIMAL_CURRENCIES = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** The languages Stripe's payment page has of the ones GaiaCP ships (GaiaCP's code => Stripe's); others: 'auto'. */
    public const LOCALES = ['en' => 'en', 'el' => 'el', 'de' => 'de', 'es' => 'es', 'fr' => 'fr', 'it' => 'it', 'nl' => 'nl', 'pl' => 'pl', 'pt_BR' => 'pt-BR', 'tr' => 'tr'];

    /** How long a checkout still being paid (a bank debit clearing) is looked up at Stripe by recheck(). */
    public const RECHECK_DAYS = 14;

    /** An amount in hundredths as Stripe takes it in this currency (thousandths for the three-decimal ones). */
    public static function stripeAmount(int $cents, string $currency): int
    {
        return in_array(strtoupper($currency), self::THREE_DECIMAL_CURRENCIES, true) ? $cents * 10 : $cents;
    }

    /** Whether the client can pay it by card now: Stripe set up, the notice unpaid and every line still due. */
    public function canPay(Invoice $invoice): bool
    {
        if (! app(PluginManager::class)->enabled('stripe') || ! StripeApi::configured() || $invoice->status !== InvoiceStatus::Unpaid
            || in_array(strtoupper($invoice->currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return false;
        }

        $items = $this->payments->unpaid($invoice);

        return $items->sum('total_cents') > 0 && $this->payments->stale($items)->isEmpty();
    }

    /**
     * Opens a Checkout Session for the notice.
     *
     * @return string Stripe's payment page, to send the client to
     *
     * @throws StripeException
     */
    public function start(Invoice $invoice): string
    {
        if (! $this->canPay($invoice)) {
            throw new StripeException(__('This payment notice can no longer be paid by card.'));
        }

        $invoice->loadMissing('client');
        $amount = $this->amountDue($invoice);
        $currency = strtolower($invoice->currency);
        $locale = $invoice->client->preferredLocale();

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => self::stripeAmount($amount, $invoice->currency),
                    'product_data' => ['name' => __('Payment notice :number', ['number' => $invoice->number], $locale)],
                ],
            ]],
            'client_reference_id' => (string) $invoice->id,
            'customer_email' => $invoice->client->email,
            'locale' => self::LOCALES[$locale] ?? 'auto',
            'metadata' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number],
            'payment_intent_data' => [
                'description' => __('Payment notice :number', ['number' => $invoice->number], $locale),
                'metadata' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number],
            ],
            // Stripe puts the session id in place of {CHECKOUT_SESSION_ID}; the braces must stay as they are.
            'success_url' => route('filament.client.stripe.return').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => PaymentNoticeResource::getUrl('view', ['record' => $invoice], panel: 'client'),
            'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES)->getTimestamp(),
        ]);

        if (blank($session['id'] ?? null) || blank($session['url'] ?? null)) {
            throw new StripeException(__('Stripe did not return a payment page.'));
        }

        StripeCheckout::create([
            'invoice_id' => $invoice->id,
            'session_id' => $session['id'],
            'livemode' => (bool) ($session['livemode'] ?? false),
            'amount_cents' => $amount,
            'currency' => $invoice->currency,
        ]);

        ActivityLog::record('stripe.checkout_started', sprintf('Card payment of %s started for payment notice %s', Money::format($amount, $invoice->currency), $invoice->number), client: $invoice->client, properties: ['session_id' => $session['id']]);

        return (string) $session['url'];
    }

    /**
     * Records the payment of a paid Checkout Session, once. A session that is not paid yet (a bank debit still
     * clearing) is left open; one GaiaCP did not open is ignored.
     *
     * @param  array<string, mixed>  $session  a Checkout Session as Stripe sends it
     */
    public function complete(array $session): ?StripeCheckout
    {
        $checkout = StripeCheckout::query()->where('session_id', (string) ($session['id'] ?? ''))->first();

        if ($checkout === null || ($session['payment_status'] ?? null) !== 'paid') {
            return $checkout;
        }

        // A test-mode event never settles a live checkout (nor the other way round)
        if ((bool) ($session['livemode'] ?? false) !== $checkout->livemode) {
            return $checkout;
        }

        $paymentIntent = is_array($session['payment_intent'] ?? null) ? ($session['payment_intent']['id'] ?? null) : ($session['payment_intent'] ?? null);

        // Whoever turns it from open to processing records it; a second report finds it taken.
        $claimed = StripeCheckout::query()->whereKey($checkout->id)->where('status', StripeCheckout::OPEN)
            ->update(['status' => StripeCheckout::PROCESSING, 'payment_intent' => $paymentIntent, 'updated_at' => now()]);

        if ($claimed === 0) {
            return $checkout->fresh();
        }

        $checkout->refresh();
        $invoice = $checkout->invoice;

        try {
            $problem = $this->problem($checkout, $session);

            if ($problem !== null) {
                return $this->unmatched($checkout, $problem);
            }

            $this->payments->handle($invoice, CarbonImmutable::now(), 'stripe', $paymentIntent ?: $checkout->session_id);
        } catch (Throwable $e) {
            // Some lines may be recorded already: never try again on our own, an admin looks at it.
            $this->unmatched($checkout, $e->getMessage());

            if (! $e instanceof RuntimeException) {
                throw $e;
            }

            return $checkout;
        }

        $checkout->update(['status' => StripeCheckout::PAID, 'paid_at' => now()]);

        ActivityLog::record('stripe.paid', sprintf('Card payment of %s received for payment notice %s', Money::format($checkout->amount_cents, $checkout->currency), $invoice->number), client: $invoice->client, properties: ['session_id' => $checkout->session_id, 'payment_intent' => $paymentIntent]);

        return $checkout;
    }

    /**
     * Payments whose recording was cut off part way (the request timed out or PHP stopped): left processing, an
     * admin would never hear of them, so they are handed over like any other unmatched payment. Hourly.
     */
    public function flagStuck(): int
    {
        $stuck = StripeCheckout::query()->where('status', StripeCheckout::PROCESSING)->where('updated_at', '<', now()->subHour())->get();

        $stuck->each(fn (StripeCheckout $checkout) => $this->unmatched($checkout, 'Recording the payment stopped part way; check which lines of the notice are paid'));

        return $stuck->count();
    }

    /**
     * Looks up at Stripe the checkouts still open, in case its webhook never came (not set up, or blocked) and the
     * client closed the page before coming back: a paid one is recorded, an expired one closed. What is recorded is
     * what Stripe's API says, as when the client comes back. Hourly.
     */
    public function recheck(): int
    {
        $open = StripeCheckout::query()->where('status', StripeCheckout::OPEN)
            ->where('created_at', '<', now()->subMinutes(self::EXPIRES_MINUTES + 5))
            ->where('created_at', '>', now()->subDays(self::RECHECK_DAYS))
            ->get();

        foreach ($open as $checkout) {
            // Each against the key of its own mode only: the mode in use now
            if ($checkout->livemode !== (StripeApi::mode() === 'live')) {
                continue;
            }

            try {
                $session = $this->stripe->checkoutSession($checkout->session_id);
            } catch (StripeException) {
                continue; // tried again next hour
            }

            if (($session['status'] ?? null) === 'expired') {
                $this->close($session, StripeCheckout::EXPIRED);
            } else {
                $this->complete($session);
            }
        }

        return $open->count();
    }

    /** Stripe closed the page unpaid (expired) or the bank refused a delayed payment (failed). */
    public function close(array $session, string $status): void
    {
        StripeCheckout::query()->where('session_id', (string) ($session['id'] ?? ''))->where('status', StripeCheckout::OPEN)->update(['status' => $status, 'updated_at' => now()]);
    }

    /** Why the paid session can't be recorded on its notice as it is now; null when it can. */
    private function problem(StripeCheckout $checkout, array $session): ?string
    {
        $invoice = $checkout->invoice;

        if ((int) ($session['amount_total'] ?? -1) !== self::stripeAmount($checkout->amount_cents, $checkout->currency) || strtoupper((string) ($session['currency'] ?? '')) !== strtoupper($checkout->currency)) {
            return 'Stripe charged a different amount than the payment page asked for';
        }

        if ($invoice === null || $invoice->status !== InvoiceStatus::Unpaid) {
            return 'The payment notice is no longer unpaid';
        }

        $items = $this->payments->unpaid($invoice);

        if ((int) $items->sum('total_cents') !== $checkout->amount_cents) {
            return 'The payment notice now asks for a different amount';
        }

        if ($this->payments->stale($items)->isNotEmpty()) {
            return 'Lines of the payment notice no longer match their service\'s due date';
        }

        return null;
    }

    private function unmatched(StripeCheckout $checkout, string $why): StripeCheckout
    {
        $checkout->update(['status' => StripeCheckout::UNMATCHED, 'error' => mb_substr($why, 0, 255)]);
        $invoice = $checkout->invoice;

        ActivityLog::record('payment.online_unmatched', sprintf(
            'Card payment of %s for payment notice %s was received by Stripe but not recorded: %s. Record it by hand or refund it in Stripe.',
            Money::format($checkout->amount_cents, $checkout->currency),
            $invoice?->number ?? '#'.$checkout->invoice_id,
            $why,
        ), client: $invoice?->client, properties: ['invoice_id' => $checkout->invoice_id, 'invoice_number' => $invoice?->number, 'reason' => $why, 'session_id' => $checkout->session_id, 'payment_intent' => $checkout->payment_intent, 'amount_cents' => $checkout->amount_cents]);

        return $checkout;
    }
}
