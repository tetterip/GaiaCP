<?php

namespace Plugins\PiraeusBank;

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
 * Card and IRIS payments of payment notices through Piraeus Bank's paycenter. start() opens a payment for what the
 * notice still owes and asks the bank for a transaction ticket for that amount; the client's browser then posts the
 * MerchantReference to the bank (form()) and comes back to one of the addresses registered with the bank (answer()).
 * Only an answer whose HashKey checks with that payment's ticket records it (RecordInvoicePayment, method
 * "piraeusbank", reference = the bank's transaction ID), once. A refusal without a HashKey (the bank sends declines that
 * way) only closes the payment, which needs the reference's random part. The bank can't be asked later, so a payment
 * that never got an answer is closed by expire(), and an IRIS transfer still pending is left for an admin.
 */
class PiraeusBankPayments
{
    /** Hours on the bank's page before a payment without an answer is closed. */
    public const ANSWER_HOURS = 2;

    public function __construct(private RecordInvoicePayment $payments, private PiraeusBankApi $api) {}

    public static function setting(string $key): string
    {
        return trim((string) config('gaiacp.piraeusbank.'.$key));
    }

    /** Whether the account details are filled in (no button until they are). */
    public static function configured(): bool
    {
        return collect(['merchant_id', 'acquirer_id', 'pos_id', 'username', 'password'])->every(fn (string $key) => self::setting($key) !== '');
    }

    /** The addresses to give the bank: it sends the client back to them (success, fail, cancel). */
    public static function answerUrl(string $result): string
    {
        return route('webhooks.piraeusbank', ['result' => $result]);
    }

    /** What paying the notice now records: its unpaid lines, with the late fees. */
    public function amountDue(Invoice $invoice): int
    {
        return (int) $this->payments->unpaid($invoice)->sum('total_cents');
    }

    /** Whether the client can pay it now: set up, in a currency the bank takes, unpaid and every line still due. */
    public function canPay(Invoice $invoice): bool
    {
        if (! app(PluginManager::class)->enabled('piraeusbank') || ! self::configured() || $invoice->status !== InvoiceStatus::Unpaid
            || ! isset(Paycenter::CURRENCIES[strtoupper($invoice->currency)])) {
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
     * Opens a payment for the notice, with its ticket from the bank.
     *
     * @throws PiraeusBankException
     */
    public function start(Invoice $invoice): PiraeusBankPayment
    {
        if (! $this->canPay($invoice)) {
            throw new PiraeusBankException(__('This payment notice can no longer be paid by card.'));
        }

        $invoice->loadMissing('client');
        $client = $invoice->client;
        $missing = self::missingDetails($client);

        if ($missing !== []) {
            throw new PiraeusBankException(__('Please fill in your :fields in your profile first: the bank needs them to check the card.', ['fields' => implode(', ', $missing)]));
        }

        $payment = PiraeusBankPayment::create([
            'invoice_id' => $invoice->id,
            'reference' => Paycenter::reference($invoice->id),
            'amount_cents' => $this->amountDue($invoice),
            'currency' => strtoupper($invoice->currency),
        ]);

        try {
            $ticket = $this->api->issueTicket($this->ticketRequest($payment, $client));
        } catch (PiraeusBankException $e) {
            $payment->update(['status' => PiraeusBankPayment::FAILED, 'error' => mb_substr($e->getMessage(), 0, 255)]);
            ActivityLog::record('piraeusbank.ticket_failed', sprintf('Piraeus Bank gave no ticket for payment notice %s: %s', $invoice->number, $e->getMessage()), client: $client, properties: ['reference' => $payment->reference]);

            // 100 = the username or password is wrong: every payment fails until an admin fixes it
            throw new PiraeusBankException(__('Paying by card is not available right now. Please try again later.'), $e->getCode(), $e);
        }

        $payment->update(['ticket' => $ticket]);

        ActivityLog::record('piraeusbank.payment_started', sprintf('Piraeus Bank card payment of %s started for payment notice %s', Money::format($payment->amount_cents, $payment->currency), $invoice->number), client: $client, properties: ['reference' => $payment->reference]);

        return $payment;
    }

    /**
     * IssueNewTicket's fields, in the original plugin's order: always a sale (RequestType 02), one instalment, and the
     * client's details for 3-D Secure.
     *
     * @return array<string, string>
     */
    public function ticketRequest(PiraeusBankPayment $payment, Client $client): array
    {
        $country = strtoupper((string) $client->country);
        $phone = Paycenter::greekPhone((string) $client->phone, $country);
        // A region code ("I", "GR-I"); a region written out is not one 3-D Secure takes
        $state = (string) preg_replace('/^'.preg_quote($country, '/').'-/', '', strtoupper(trim((string) $client->state)));
        $state = preg_match('/^[A-Z0-9]{1,3}$/', $state) ? $state : '';

        $address = [
            'City' => mb_substr((string) $client->city, 0, 50),
            'Country' => Paycenter::countryCode($country),
            'Line1' => mb_substr((string) $client->address1, 0, 50),
            'PostCode' => mb_substr((string) $client->postcode, 0, 16),
            'State' => $state,
        ];

        return [
            'Username' => self::setting('username'),
            'Password' => Paycenter::passwordDigest(self::setting('password')),
            'MerchantId' => self::setting('merchant_id'),
            'PosId' => self::setting('pos_id'),
            'AcquirerId' => self::setting('acquirer_id'),
            'MerchantReference' => $payment->reference,
            'RequestType' => '02',
            'ExpirePreauth' => '0',
            'Amount' => Paycenter::amount($payment->amount_cents),
            'CurrencyCode' => Paycenter::CURRENCIES[$payment->currency],
            'Installments' => '1',
            'Bnpl' => '0',
            'Parameters' => '',
            ...collect($address)->mapWithKeys(fn (string $value, string $name) => ['BillAddr'.$name => $value])->all(),
            ...collect($address)->mapWithKeys(fn (string $value, string $name) => ['ShipAddr'.$name => $value])->all(),
            'CardholderName' => mb_substr(Str::ascii(trim($client->first_name.' '.$client->last_name)), 0, 45),
            'Email' => (string) $client->email,
            'HomePhone' => $phone !== null && $phone['type'] === 'home' ? $phone['number'] : '',
            'MobilePhone' => $phone !== null && $phone['type'] === 'mobile' ? $phone['number'] : '',
            'WorkPhone' => '',
        ];
    }

    /**
     * The form the client's browser posts to the bank: the account and the payment's MerchantReference (the ticket
     * already holds the amount).
     *
     * @return array{url: string, fields: array<string, string>}
     */
    public function form(PiraeusBankPayment $payment): array
    {
        return ['url' => Paycenter::PAY_URL, 'fields' => [
            'AcquirerId' => self::setting('acquirer_id'),
            'MerchantId' => self::setting('merchant_id'),
            'PosID' => self::setting('pos_id'),
            'User' => self::setting('username'),
            'LanguageCode' => Paycenter::language($payment->invoice->client->preferredLocale()),
            'MerchantReference' => $payment->reference,
        ]];
    }

    /**
     * The bank's answer on one of its three addresses, as the client's browser brought it back.
     *
     * @param  'success'|'fail'|'cancel'  $result
     * @param  array<string, string>  $answer
     * @return PiraeusBankPayment|null the payment it names; null when it names none GaiaCP opened
     */
    public function answer(string $result, array $answer): ?PiraeusBankPayment
    {
        $payment = PiraeusBankPayment::query()->where('reference', $answer['MerchantReference'] ?? '')->whereNotNull('ticket')->first();

        if ($payment === null) {
            return null;
        }

        $signed = Paycenter::verify($answer, (string) $payment->ticket, self::setting('pos_id'), self::setting('acquirer_id'));
        $hashKey = trim($answer['HashKey'] ?? '') !== '';

        if ($hashKey && ! $signed) {
            // Anyone can open these addresses: never believed, only noted.
            ActivityLog::record('piraeusbank.hash_failed', sprintf('A Piraeus Bank answer for payment notice %s failed the HashKey check and was ignored; check the payment in the bank\'s AdminTool.', $payment->invoice->number ?? '#'.$payment->invoice_id), client: $payment->invoice?->client, properties: ['reference' => $payment->reference]);

            return $payment;
        }

        $details = array_filter([
            'transaction_id' => mb_substr($answer['TransactionId'] ?? '', 0, 255),
            'support_reference' => mb_substr($answer['SupportReferenceID'] ?? '', 0, 255),
            'pay_method' => mb_substr($answer['PaymentMethod'] ?? '', 0, 50),
        ], fn (string $value) => $value !== '');
        $code = trim($answer['ResponseCode'] ?? '');

        if ($result === 'cancel') {
            return $this->close($payment, PiraeusBankPayment::CANCELLED, 'Cancelled on the bank\'s page');
        }

        if ($signed && Paycenter::irisPending($answer)) {
            return $this->pending($payment, $details);
        }

        if ($result === 'success' && $signed && trim($answer['ResultCode'] ?? '') === '0' && in_array(ltrim($code, '0') ?: '0', Paycenter::APPROVED, true)) {
            return $this->settle($payment, $details);
        }

        if ($result === 'success' && $code === Paycenter::ALREADY_APPROVED) {
            return $payment; // the bank saw this reference paid before: its first answer recorded it
        }

        if ($result === 'success' && ! $signed) {
            return $payment; // an approval must be signed; nothing else is believed on this address
        }

        return $this->close($payment, PiraeusBankPayment::FAILED, trim('ResponseCode '.$code.' '.($answer['ResponseDescription'] ?? $answer['ResultDescription'] ?? '')));
    }

    /** Payments the bank never answered: closed after ANSWER_HOURS, each named in the activity log. Hourly. */
    public function expire(): int
    {
        $waiting = PiraeusBankPayment::query()->with('invoice')->where('status', PiraeusBankPayment::OPEN)->whereNotNull('ticket')
            ->where('created_at', '<', now()->subHours(self::ANSWER_HOURS))->get();

        foreach ($waiting as $payment) {
            if ($this->close($payment, PiraeusBankPayment::EXPIRED, 'No answer from the bank')->status === PiraeusBankPayment::EXPIRED) {
                ActivityLog::record('piraeusbank.no_answer', sprintf('Piraeus Bank card payment of %s for payment notice %s got no answer from the bank: the client most likely left its page. If the client says they paid, look for reference %s in the bank\'s AdminTool.', Money::format($payment->amount_cents, $payment->currency), $payment->invoice->number ?? '#'.$payment->invoice_id, $payment->reference), client: $payment->invoice?->client, properties: ['reference' => $payment->reference]);
            }
        }

        return $waiting->count();
    }

    /** Payments whose recording was cut off part way: handed over like any other unmatched payment. Hourly. */
    public function flagStuck(): int
    {
        $stuck = PiraeusBankPayment::query()->where('status', PiraeusBankPayment::PROCESSING)->where('updated_at', '<', now()->subHour())->get();

        $stuck->each(fn (PiraeusBankPayment $payment) => $this->unmatched($payment, 'Recording the payment stopped part way; check which lines of the notice are paid'));

        return $stuck->count();
    }

    /** @param  array<string, string>  $details */
    private function settle(PiraeusBankPayment $payment, array $details): PiraeusBankPayment
    {
        // Whoever turns it to processing records it; a repeated answer finds it taken.
        $claimed = PiraeusBankPayment::query()->whereKey($payment->id)
            ->whereIn('status', [PiraeusBankPayment::OPEN, PiraeusBankPayment::EXPIRED, PiraeusBankPayment::PENDING])
            ->update(['status' => PiraeusBankPayment::PROCESSING, 'updated_at' => now()] + $details);
        $payment->refresh();

        if ($claimed === 0) {
            return $payment;
        }

        $invoice = $payment->invoice;

        try {
            $problem = $this->problem($payment);

            if ($problem !== null) {
                return $this->unmatched($payment, $problem);
            }

            $this->payments->handle($invoice, CarbonImmutable::now(), 'piraeusbank', $payment->transaction_id ?? $payment->support_reference ?? $payment->reference);
        } catch (Throwable $e) {
            // Some lines may be recorded already: never try again on our own, an admin looks at it.
            $this->unmatched($payment, $e->getMessage());

            if (! $e instanceof RuntimeException) {
                throw $e;
            }

            return $payment;
        }

        $payment->update(['status' => PiraeusBankPayment::PAID, 'paid_at' => now(), 'error' => null]);

        ActivityLog::record('piraeusbank.paid', sprintf('Piraeus Bank card payment of %s received for payment notice %s', Money::format($payment->amount_cents, $payment->currency), $invoice->number), client: $invoice->client, properties: ['reference' => $payment->reference, 'transaction_id' => $payment->transaction_id, 'support_reference' => $payment->support_reference]);

        return $payment;
    }

    /** @param  array<string, string>  $details */
    private function pending(PiraeusBankPayment $payment, array $details): PiraeusBankPayment
    {
        $changed = PiraeusBankPayment::query()->whereKey($payment->id)->whereIn('status', [PiraeusBankPayment::OPEN, PiraeusBankPayment::EXPIRED])
            ->update(['status' => PiraeusBankPayment::PENDING, 'updated_at' => now()] + $details);
        $payment->refresh();

        if ($changed > 0) {
            ActivityLog::record('payment.online_unmatched', sprintf(
                'Piraeus Bank IRIS payment of %s for payment notice %s was started but not confirmed by the bank yet (ResponseCode 09). It may still go through: check it in the bank\'s AdminTool (support reference %s) and record it by hand once it has.',
                Money::format($payment->amount_cents, $payment->currency),
                $payment->invoice->number ?? '#'.$payment->invoice_id,
                $payment->support_reference ?? '-',
            ), client: $payment->invoice?->client, properties: ['invoice_id' => $payment->invoice_id, 'invoice_number' => $payment->invoice?->number, 'reason' => 'IRIS transfer not confirmed yet', 'reference' => $payment->reference, 'amount_cents' => $payment->amount_cents]);
        }

        return $payment;
    }

    private function close(PiraeusBankPayment $payment, string $status, string $why): PiraeusBankPayment
    {
        PiraeusBankPayment::query()->whereKey($payment->id)->where('status', PiraeusBankPayment::OPEN)
            ->update(['status' => $status, 'error' => mb_substr($why, 0, 255), 'updated_at' => now()]);

        return $payment->refresh();
    }

    /** Why a paid answer can't be recorded on its notice as it is now; null when it can. */
    private function problem(PiraeusBankPayment $payment): ?string
    {
        $invoice = $payment->invoice;

        if ($invoice === null || $invoice->status !== InvoiceStatus::Unpaid) {
            return 'The payment notice is no longer unpaid';
        }

        $items = $this->payments->unpaid($invoice);

        if ((int) $items->sum('total_cents') !== $payment->amount_cents || strtoupper($invoice->currency) !== $payment->currency) {
            return 'The payment notice now asks for a different amount';
        }

        if ($this->payments->stale($items)->isNotEmpty()) {
            return 'Lines of the payment notice no longer match their service\'s due date';
        }

        return null;
    }

    private function unmatched(PiraeusBankPayment $payment, string $why): PiraeusBankPayment
    {
        $payment->update(['status' => PiraeusBankPayment::UNMATCHED, 'error' => mb_substr($why, 0, 255)]);
        $invoice = $payment->invoice;

        ActivityLog::record('payment.online_unmatched', sprintf(
            'Piraeus Bank card payment of %s for payment notice %s was taken by the bank but not recorded: %s. Record it by hand or refund it in the bank\'s AdminTool.',
            Money::format($payment->amount_cents, $payment->currency),
            $invoice->number ?? '#'.$payment->invoice_id,
            $why,
        ), client: $invoice?->client, properties: ['invoice_id' => $payment->invoice_id, 'invoice_number' => $invoice?->number, 'reason' => $why, 'reference' => $payment->reference, 'transaction_id' => $payment->transaction_id, 'amount_cents' => $payment->amount_cents]);

        return $payment;
    }
}
