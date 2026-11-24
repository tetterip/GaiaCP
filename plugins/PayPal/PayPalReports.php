<?php

namespace Plugins\PayPal;

use App\Payments\ProviderReports;
use Illuminate\Support\Str;

/**
 * Refunds and chargebacks (disputes) PayPal reports through its webhook, handed to GaiaCP's ProviderReports (bell and
 * log, nothing changed on its own). As for payments, the webhook is only a hint: the refund or dispute it names is
 * read from PayPal's API with the app's own keys, and only a capture of an order GaiaCP opened, in the mode in use, counts.
 */
class PayPalReports
{
    public const REFUND_EVENTS = ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'];

    public const DISPUTE_EVENTS = ['CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.UPDATED', 'CUSTOMER.DISPUTE.RESOLVED'];

    /** How a closed dispute ended with the money staying with you; any other outcome gave it back to the client. */
    public const WON = ['RESOLVED_SELLER_FAVOUR', 'CANCELED_BY_BUYER', 'DENIED', 'RESOLVED_WITH_PAYOUT'];

    public function __construct(private PayPalApi $paypal, private ProviderReports $reports) {}

    /**
     * @param  array<string, mixed>  $resource  the event's resource, only for the id it names
     *
     * @throws PayPalException PayPal could not be read (it sends the event again later)
     */
    public function handle(string $type, array $resource): void
    {
        if (in_array($type, self::REFUND_EVENTS, true) && is_string($id = $resource['id'] ?? null) && $id !== '') {
            $this->refund($id);
        } elseif (in_array($type, self::DISPUTE_EVENTS, true) && is_string($id = $resource['dispute_id'] ?? null) && $id !== '') {
            $this->dispute($id);
        }
    }

    private function refund(string $id): void
    {
        try {
            $refund = $this->paypal->refundDetails($id);
        } catch (PayPalException) {
            // A reversal may name the capture itself
            $capture = $this->paypal->captureDetails($id);

            if (in_array($capture['status'] ?? null, ['REFUNDED', 'REVERSED'], true) && $this->ours($id)) {
                $this->reports->refunded('paypal', $id, (int) PayPalPayments::cents(data_get($capture, 'amount.value')), (string) data_get($capture, 'amount.currency_code'));
            }

            return;
        }

        $up = collect($refund['links'] ?? [])->first(fn ($link) => ($link['rel'] ?? null) === 'up')['href'] ?? '';
        $captureId = Str::afterLast(rtrim((string) $up, '/'), '/');
        $total = data_get($refund, 'seller_payable_breakdown.total_refunded_amount') ?? ($refund['amount'] ?? []);

        if (($refund['status'] ?? null) !== 'COMPLETED' || $captureId === '' || ! $this->ours($captureId)
            || ($cents = PayPalPayments::cents($total['value'] ?? null)) === null) {
            return;
        }

        $this->reports->refunded('paypal', $captureId, $cents, (string) ($total['currency_code'] ?? ''));
    }

    private function dispute(string $id): void
    {
        $dispute = $this->paypal->dispute($id);
        $captureId = (string) data_get($dispute, 'disputed_transactions.0.seller_transaction_id', '');
        $cents = PayPalPayments::cents(data_get($dispute, 'dispute_amount.value'));
        $currency = (string) data_get($dispute, 'dispute_amount.currency_code', '');
        $reason = filled($dispute['reason'] ?? null) ? strtolower(str_replace('_', ' ', (string) $dispute['reason'])) : null;

        if ($captureId === '' || $cents === null || ! $this->ours($captureId)) {
            return;
        }

        if (($dispute['status'] ?? null) === 'RESOLVED') {
            $this->reports->disputeClosed('paypal', $captureId, $id, in_array(data_get($dispute, 'dispute_outcome.outcome_code'), self::WON, true), $cents, $currency, $reason);
        } else {
            $this->reports->disputed('paypal', $captureId, $id, $cents, $currency, $reason);
        }
    }

    /** A capture of an order GaiaCP opened, in the mode in use (the keys that read it). */
    private function ours(string $captureId): bool
    {
        return PayPalOrder::query()->where('capture_id', $captureId)->where('live', PayPalApi::mode() === 'live')->exists();
    }
}
