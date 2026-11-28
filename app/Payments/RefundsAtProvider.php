<?php

namespace App\Payments;

use App\Models\Payment;
use RuntimeException;

/**
 * Optional for a PaymentModule whose gateway can give money back (Stripe, PayPal): Refund payment then offers
 * "Refund at <gateway> too" for its payments, and App\Actions\RefundPayment records the refund only once the
 * gateway has taken it.
 */
interface RefundsAtProvider
{
    /** Whether this payment, recorded with this module's key, can be given back at the gateway now (keys set, its mode). */
    public function canRefundAtProvider(Payment $payment): bool;

    /**
     * Gives the payment's whole amount back at the gateway. A second call for the same payment must not give it back
     * twice (an idempotency key built from the payment's id).
     *
     * @return string the gateway's id of the refund
     *
     * @throws RuntimeException with a translated message when the gateway refused or could not be reached
     */
    public function refundAtProvider(Payment $payment): string;
}
