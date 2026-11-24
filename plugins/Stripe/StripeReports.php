<?php

namespace Plugins\Stripe;

use App\Payments\ProviderReports;

/**
 * Refunds and chargebacks Stripe reports through its signed webhook, handed to GaiaCP's ProviderReports (bell and log,
 * nothing changed on its own). Only for payments of checkouts GaiaCP opened, in the event's own mode.
 */
class StripeReports
{
    public function __construct(private ProviderReports $reports) {}

    /**
     * @param  array<string, mixed>  $object  the event's data.object: a Charge or a Dispute
     */
    public function handle(string $type, array $object, bool $live): void
    {
        $paymentIntent = $this->paymentIntent($object['payment_intent'] ?? null);
        $currency = strtoupper((string) ($object['currency'] ?? ''));

        if ($paymentIntent === null || $currency === '' || ! $this->ours($paymentIntent, $live)) {
            return;
        }

        $disputeId = (string) ($object['id'] ?? '');
        $reason = filled($object['reason'] ?? null) ? str_replace('_', ' ', (string) $object['reason']) : null;
        $amount = self::fromStripeAmount((int) ($object['amount'] ?? 0), $currency);

        match ($type) {
            'charge.refunded' => $this->reports->refunded('stripe', $paymentIntent, self::fromStripeAmount((int) ($object['amount_refunded'] ?? 0), $currency), $currency),
            'charge.dispute.created' => $disputeId !== '' ? $this->reports->disputed('stripe', $paymentIntent, $disputeId, $amount, $currency, $reason) : null,
            // won, or an inquiry closed without a chargeback (warning_closed): the money stays
            'charge.dispute.closed' => $disputeId !== '' ? $this->reports->disputeClosed('stripe', $paymentIntent, $disputeId, ($object['status'] ?? null) !== 'lost', $amount, $currency, $reason) : null,
            default => null,
        };
    }

    /** An amount as Stripe sends it in hundredths (thousandths for the three-decimal currencies). */
    public static function fromStripeAmount(int $amount, string $currency): int
    {
        return in_array(strtoupper($currency), StripeCheckouts::THREE_DECIMAL_CURRENCIES, true) ? intdiv($amount, 10) : $amount;
    }

    private function paymentIntent(mixed $value): ?string
    {
        $id = is_array($value) ? ($value['id'] ?? null) : $value;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** A payment of a checkout GaiaCP opened, in the same mode as the event (a test event never touches a live payment). */
    private function ours(string $paymentIntent, bool $live): bool
    {
        return StripeCheckout::query()->where('payment_intent', $paymentIntent)->where('livemode', $live)->exists();
    }
}
