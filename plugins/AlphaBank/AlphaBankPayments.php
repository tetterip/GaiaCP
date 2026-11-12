<?php

namespace Plugins\AlphaBank;

use App\Actions\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Plugins\PluginManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Card payments of payment notices through Alpha Bank e-Commerce (Cardlink's redirect flow). start() opens a payment
 * for what the notice still owes; the client's browser posts the signed form (form()) to the bank and comes back with
 * the bank's signed answer (answer()). Only an answer whose digest checks with the shared secret and that names this
 * payment, the merchant ID and the amount asked for is believed; a paid one is recorded (RecordInvoicePayment, method
 * "alphabank", reference = the bank's payment reference) once, whoever sends it first. The bank has no way to be asked
 * later, so a payment that never got an answer is closed by expire() and named in the activity log.
 */
class AlphaBankPayments
{
    /** Hours on the bank's page before a payment without an answer is closed. */
    public const ANSWER_HOURS = 2;

    public function __construct(private RecordInvoicePayment $payments) {}

    public static function live(): bool
    {
        return config('gaiacp.alphabank.mode') === 'live';
    }

    public static function merchantId(): string
    {
        return trim((string) config('gaiacp.alphabank.merchant_id'));
    }

    public static function secret(): string
    {
        return (string) config('gaiacp.alphabank.shared_secret');
    }

    /** Whether the merchant ID and shared secret are filled in (no button until they are). */
    public static function configured(): bool
    {
        return self::merchantId() !== '' && self::secret() !== '';
    }

    public static function endpoint(): string
    {
        return self::live() ? Cardlink::LIVE_URL : Cardlink::TEST_URL;
    }

    /** Where the bank sends the client back: the address that answer() reads. */
    public static function answerUrl(string $result): string
    {
        return route('webhooks.alphabank', ['result' => $result]);
    }

    /** What paying the notice now records: its unpaid lines, with the late fees. */
    public function amountDue(Invoice $invoice): int
    {
        return (int) $this->payments->unpaid($invoice)->sum('total_cents');
    }

    /** Whether the client can pay it by card now: set up, in euro, unpaid and every line still due. */
    public function canPay(Invoice $invoice): bool
    {
        if (! app(PluginManager::class)->enabled('alphabank') || ! self::configured() || $invoice->status !== InvoiceStatus::Unpaid
            || strtoupper($invoice->currency) !== Cardlink::CURRENCY) {
            return false;
        }

        $items = $this->payments->unpaid($invoice);

        return $items->sum('total_cents') > 0 && $this->payments->stale($items)->isEmpty();
    }

    /**
     * The client's details the bank needs for 3-D Secure, by name, that the client has not filled in.
     *
     * @return list<string>
     */
    public static function missingDetails(Client $client): array
    {
        $labels = ['email' => __('Email'), 'address1' => __('Address'), 'city' => __('City'), 'postcode' => __('Postcode'), 'country' => __('Country')];

        return array_values(array_filter($labels, fn (string $label, string $field) => trim((string) $client->{$field}) === '', ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Opens a payment for the notice.
     *
     * @throws AlphaBankException
     */
    public function start(Invoice $invoice): AlphaBankPayment
    {
        if (! $this->canPay($invoice)) {
            throw new AlphaBankException(__('This payment notice can no longer be paid by card.'));
        }

        $invoice->loadMissing('client');
        $missing = self::missingDetails($invoice->client);

        if ($missing !== []) {
            throw new AlphaBankException(__('Please fill in your :fields in your profile first: the bank needs them to check the card.', ['fields' => implode(', ', $missing)]));
        }

        $amount = $this->amountDue($invoice);

        // A new orderid for every attempt (the bank refuses one it has seen), letters and digits only.
        $payment = AlphaBankPayment::create([
            'invoice_id' => $invoice->id,
            'reference' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $invoice->number), 0, 25).'T'.strtoupper(Str::random(16)),
            'live' => self::live(),
            'amount_cents' => $amount,
            'currency' => Cardlink::CURRENCY,
        ]);

        ActivityLog::record('alphabank.payment_started', sprintf('Alpha Bank card payment of %s started for payment notice %s', Money::format($amount, $payment->currency), $invoice->number), client: $invoice->client, properties: ['reference' => $payment->reference]);

        return $payment;
    }

    /**
     * The form the client's browser posts to the bank, signed: the fields in the order they are signed, with the digest.
     *
     * @return array{url: string, fields: array<string, string>}
     */
    public function form(AlphaBankPayment $payment): array
    {
        $invoice = $payment->invoice;
        $client = $invoice->client;
        $country = strtoupper((string) $client->country);

        $fields = array_filter([
            'version' => '2',
            'mid' => self::merchantId(),
            'lang' => str_starts_with($client->preferredLocale(), 'el') ? 'el' : 'en',
            'orderid' => $payment->reference,
            'orderDesc' => mb_substr(__('Payment notice :number', ['number' => $invoice->number], $client->preferredLocale()), 0, 128),
            'orderAmount' => Cardlink::amount($payment->amount_cents),
            'currency' => $payment->currency,
            'payerEmail' => (string) $client->email,
            'billCountry' => $country,
            // Greek regions are not ISO 3166-2 subdivisions the bank's 3-D Secure takes
            'billState' => $country === 'GR' ? '' : (string) $client->state,
            'billZip' => (string) $client->postcode,
            'billCity' => mb_substr((string) $client->city, 0, 50),
            'billAddress' => mb_substr((string) $client->address1, 0, 50),
            'trType' => '1', // a sale: the money is taken at once
            'confirmUrl' => self::answerUrl('success'),
            'cancelUrl' => self::answerUrl('failure'),
        ], fn (string $value) => $value !== '');

        return ['url' => self::endpoint(), 'fields' => $fields + ['digest' => Cardlink::sign($fields, self::secret())]];
    }

    /**
     * The bank's answer, as the client's browser brought it back. Nothing changes unless its digest checks; then a paid
     * payment is recorded once, a cancelled or refused one is closed.
     *
     * @param  array<string, string>  $post  the raw POST fields, in the order received
     * @return AlphaBankPayment|null the payment it names; null when it names none or is not signed by the bank
     */
    public function answer(array $post): ?AlphaBankPayment
    {
        $payment = AlphaBankPayment::query()->where('reference', (string) ($post['orderid'] ?? ''))->first();

        if ($payment === null || ! self::configured()) {
            return null;
        }

        if (! Cardlink::verify($post, self::secret())) {
            // Anyone can post to this address: never believed, only noted.
            ActivityLog::record('alphabank.digest_failed', sprintf('An Alpha Bank answer for payment notice %s failed the digest check and was ignored; check the payment in the bank\'s back office. A wrong shared secret causes this too.', $payment->invoice->number ?? '#'.$payment->invoice_id), client: $payment->invoice?->client, properties: ['reference' => $payment->reference]);

            return null;
        }

        $status = strtoupper($post['status'] ?? '');
        $details = [
            'transaction_id' => mb_substr($post['txId'] ?? '', 0, 255) ?: null,
            'payment_reference' => mb_substr($post['paymentRef'] ?? '', 0, 255) ?: null,
            'pay_method' => mb_substr($post['payMethod'] ?? '', 0, 50) ?: null,
        ];

        if (in_array($status, ['CAPTURED', 'AUTHORIZED'], true)) {
            return $this->settle($payment, $post, $details);
        }

        $why = trim(($status !== '' ? $status : 'UNKNOWN').' '.($post['message'] ?? ''));

        return $this->close($payment, $status === 'CANCELED' ? AlphaBankPayment::CANCELLED : AlphaBankPayment::FAILED, $why);
    }

    /**
     * Payments the bank never answered: the client left its page (or closed the browser after paying, which the bank's
     * back office shows). Closed after ANSWER_HOURS, each named in the activity log; an answer that comes later still
     * records a paid one. Hourly.
     */
    public function expire(): int
    {
        $waiting = AlphaBankPayment::query()->with('invoice')->where('status', AlphaBankPayment::OPEN)
            ->where('created_at', '<', now()->subHours(self::ANSWER_HOURS))->get();

        foreach ($waiting as $payment) {
            if ($this->close($payment, AlphaBankPayment::EXPIRED, 'No answer from the bank')->status === AlphaBankPayment::EXPIRED) {
                ActivityLog::record('alphabank.no_answer', sprintf('Alpha Bank card payment of %s for payment notice %s got no answer from the bank: the client most likely left its page. If the client says they paid, look for order %s in the bank\'s back office.', Money::format($payment->amount_cents, $payment->currency), $payment->invoice->number ?? '#'.$payment->invoice_id, $payment->reference), client: $payment->invoice?->client, properties: ['reference' => $payment->reference]);
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
        $stuck = AlphaBankPayment::query()->where('status', AlphaBankPayment::PROCESSING)->where('updated_at', '<', now()->subHour())->get();

        $stuck->each(fn (AlphaBankPayment $payment) => $this->unmatched($payment, 'Recording the payment stopped part way; check which lines of the notice are paid'));

        return $stuck->count();
    }

    /**
     * Records a payment the bank took, once.
     *
     * @param  array<string, string>  $post
     * @param  array<string, string|null>  $details
     */
    private function settle(AlphaBankPayment $payment, array $post, array $details): AlphaBankPayment
    {
        // Whoever turns it from open (or expired) to processing records it; a repeated answer finds it taken.
        $claimed = AlphaBankPayment::query()->whereKey($payment->id)->whereIn('status', [AlphaBankPayment::OPEN, AlphaBankPayment::EXPIRED])
            ->update(['status' => AlphaBankPayment::PROCESSING, 'updated_at' => now()] + $details);
        $payment->refresh();

        if ($claimed === 0) {
            return $payment;
        }

        $invoice = $payment->invoice;

        try {
            $problem = $this->problem($payment, $post);

            if ($problem !== null) {
                return $this->unmatched($payment, $problem);
            }

            $this->payments->handle($invoice, CarbonImmutable::now(), 'alphabank', $payment->payment_reference ?? $payment->transaction_id ?? $payment->reference);
        } catch (Throwable $e) {
            // Some lines may be recorded already: never try again on our own, an admin looks at it.
            $this->unmatched($payment, $e->getMessage());

            if (! $e instanceof RuntimeException) {
                throw $e;
            }

            return $payment;
        }

        $payment->update(['status' => AlphaBankPayment::PAID, 'paid_at' => now(), 'error' => null]);

        ActivityLog::record('alphabank.paid', sprintf('Alpha Bank card payment of %s received for payment notice %s', Money::format($payment->amount_cents, $payment->currency), $invoice->number), client: $invoice->client, properties: ['reference' => $payment->reference, 'transaction_id' => $payment->transaction_id, 'payment_reference' => $payment->payment_reference]);

        return $payment;
    }

    private function close(AlphaBankPayment $payment, string $status, string $why): AlphaBankPayment
    {
        AlphaBankPayment::query()->whereKey($payment->id)->where('status', AlphaBankPayment::OPEN)
            ->update(['status' => $status, 'error' => mb_substr($why, 0, 255), 'updated_at' => now()]);

        return $payment->refresh();
    }

    /**
     * Why a paid answer can't be recorded on its notice as it is now; null when it can.
     *
     * @param  array<string, string>  $post
     */
    private function problem(AlphaBankPayment $payment, array $post): ?string
    {
        if (($post['mid'] ?? '') !== self::merchantId()) {
            return 'The bank\'s answer names another merchant ID';
        }

        if (Cardlink::cents($post['orderAmount'] ?? '') !== $payment->amount_cents || strtoupper($post['currency'] ?? '') !== $payment->currency) {
            return 'The bank took a different amount than the payment asked for';
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

    private function unmatched(AlphaBankPayment $payment, string $why): AlphaBankPayment
    {
        $payment->update(['status' => AlphaBankPayment::UNMATCHED, 'error' => mb_substr($why, 0, 255)]);
        $invoice = $payment->invoice;

        ActivityLog::record('payment.online_unmatched', sprintf(
            'Alpha Bank card payment of %s for payment notice %s was taken by the bank but not recorded: %s. Record it by hand or refund it in the bank\'s back office.',
            Money::format($payment->amount_cents, $payment->currency),
            $invoice->number ?? '#'.$payment->invoice_id,
            $why,
        ), client: $invoice?->client, properties: ['invoice_id' => $payment->invoice_id, 'invoice_number' => $invoice?->number, 'reason' => $why, 'reference' => $payment->reference, 'transaction_id' => $payment->transaction_id, 'amount_cents' => $payment->amount_cents]);

        return $payment;
    }
}
