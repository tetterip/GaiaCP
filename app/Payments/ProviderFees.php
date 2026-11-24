<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * The fee a payment provider kept of a payment (Stripe's balance transaction fee, PayPal's paypal_fee), stored on the
 * payments it recorded (`payments.fee_cents`) for the income reports. One gateway payment can be several payments here
 * (a line each of its notice): the fee is shared out by their amounts, to the cent. Recorded once; a later report
 * of the same payment changes nothing.
 */
class ProviderFees
{
    /**
     * @param  string  $method  the payment module's key, as on the payments
     * @param  string  $reference  the payments' reference (Stripe's PaymentIntent, PayPal's capture)
     * @param  int  $feeCents  in $currency, which must be the payments' own (another one is not stored)
     * @return int how many payments got their fee
     */
    public function record(string $method, string $reference, int $feeCents, string $currency): int
    {
        if ($method === '' || $reference === '' || $feeCents < 0) {
            return 0;
        }

        return DB::transaction(function () use ($method, $reference, $feeCents, $currency) {
            $payments = Payment::query()->where('method', $method)->where('reference', $reference)
                ->where('amount_cents', '>', 0)->whereNull('refund_of')->orderBy('id')->lockForUpdate()->get();

            if ($payments->isEmpty() || $payments->contains(fn (Payment $p) => $p->fee_cents !== null || strtoupper($p->currency) !== strtoupper($currency))) {
                return 0;
            }

            $total = (int) $payments->sum('amount_cents');
            $shares = $payments->mapWithKeys(fn (Payment $p) => [$p->id => intdiv($feeCents * $p->amount_cents, $total)])->all();
            $left = $feeCents - array_sum($shares);

            // The cents lost to rounding go to the payments that lost most (largest remainder)
            foreach ($payments->sortByDesc(fn (Payment $p) => ($feeCents * $p->amount_cents) % $total)->take($left) as $payment) {
                $shares[$payment->id]++;
            }

            foreach ($payments as $payment) {
                $payment->update(['fee_cents' => $shares[$payment->id]]);
            }

            return $payments->count();
        });
    }
}
