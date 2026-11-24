<?php

namespace App\Payments;

use App\Models\ActivityLog;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\ProviderPaymentState;
use App\Models\Service;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a payment gateway tells GaiaCP about a payment after it was recorded: money given back at the provider (from
 * its dashboard, or by Refund payment here) and chargebacks. A payment plugin calls these from its webhook, with what
 * it checked came from the provider. Nothing is changed on its own: the payment, notice and service stay as they are,
 * the admins get the bell (and the activity log a line) so they decide, with Refund payment, a suspension or nothing.
 * Each report is acted on once: a repeated or older one finds the state already there.
 */
class ProviderReports
{
    /**
     * The provider says it has given back $totalCents of the payment so far (all refunds together). The bell rings
     * only for what GaiaCP has not recorded as refunded (Refund payment at the provider records it first).
     *
     * @param  string  $method  the payment module's key, as on the payments (`stripe`)
     * @param  string  $reference  the payments' reference (Stripe's PaymentIntent, PayPal's capture)
     */
    public function refunded(string $method, string $reference, int $totalCents, string $currency): void
    {
        $this->locked($method, $reference, $currency, function (ProviderPaymentState $state, Collection $payments) use ($method, $reference, $totalCents) {
            if ($totalCents <= $state->refunded_cents) {
                return; // known already, or an older report arriving late
            }

            $state->update(['refunded_cents' => $totalCents]);
            $recorded = $this->refundedHere($payments);

            if ($recorded >= $totalCents) {
                return; // given back through Refund payment: nothing new for the admins
            }

            $first = $payments->first();
            ActivityLog::record('payment.provider_refunded', sprintf(
                '%s gave back %s of payment %s%s; GaiaCP has %s recorded as refunded. Use Refund payment if it stays refunded.',
                $this->provider($method),
                Money::format($totalCents, $state->currency),
                $reference,
                $this->notice($payments),
                Money::format($recorded, $state->currency),
            ), $first->service, properties: $this->properties($method, $reference, $payments) + [
                'refunded_cents' => $totalCents,
                'recorded_cents' => $recorded,
                'currency' => $state->currency,
            ]);
        });
    }

    /** The client disputed the payment with their bank or the provider (a chargeback): the money is held back. */
    public function disputed(string $method, string $reference, string $disputeId, int $amountCents, string $currency, ?string $reason = null): void
    {
        $this->locked($method, $reference, $currency, function (ProviderPaymentState $state, Collection $payments) use ($method, $reference, $disputeId, $amountCents, $reason) {
            if ($state->dispute_id === $disputeId) {
                return; // known already (or closed already: a late "created" changes nothing)
            }

            $state->update([
                'dispute_id' => $disputeId,
                'dispute_status' => ProviderPaymentState::DISPUTE_OPEN,
                'dispute_cents' => $amountCents,
                'dispute_reason' => filled($reason) ? mb_substr($reason, 0, 191) : null,
            ]);

            ActivityLog::record('payment.disputed', sprintf(
                'Chargeback: %s of payment %s%s is disputed at %s%s. Nothing was changed here; answer it at %s.',
                Money::format($amountCents, $state->currency),
                $reference,
                $this->notice($payments),
                $this->provider($method),
                filled($reason) ? " ({$reason})" : '',
                $this->provider($method),
            ), $payments->first()->service, properties: $this->properties($method, $reference, $payments) + [
                'dispute_id' => $disputeId,
                'amount_cents' => $amountCents,
                'currency' => $state->currency,
                'reason' => $reason,
            ]);
        });
    }

    /** The dispute is over: $won = the money stays with you, otherwise it went back to the client. */
    public function disputeClosed(string $method, string $reference, string $disputeId, bool $won, int $amountCents, string $currency, ?string $reason = null): void
    {
        $status = $won ? ProviderPaymentState::DISPUTE_WON : ProviderPaymentState::DISPUTE_LOST;

        $this->locked($method, $reference, $currency, function (ProviderPaymentState $state, Collection $payments) use ($method, $reference, $disputeId, $status, $won, $amountCents, $reason) {
            if ($state->dispute_id === $disputeId && $state->dispute_status === $status) {
                return;
            }

            $state->update([
                'dispute_id' => $disputeId,
                'dispute_status' => $status,
                'dispute_cents' => $amountCents,
                'dispute_reason' => filled($reason) ? mb_substr($reason, 0, 191) : $state->dispute_reason,
            ]);

            ActivityLog::record('payment.dispute_closed', sprintf(
                $won
                    ? 'Chargeback won: %s of payment %s%s stays paid (%s).'
                    : 'Chargeback lost: %s of payment %s%s went back to the client (%s). Use Refund payment, and suspend or cancel the service if it ends.',
                Money::format($amountCents, $state->currency),
                $reference,
                $this->notice($payments),
                $this->provider($method),
            ), $payments->first()->service, properties: $this->properties($method, $reference, $payments) + [
                'dispute_id' => $disputeId,
                'won' => $won,
                'amount_cents' => $amountCents,
                'currency' => $state->currency,
            ]);
        });
    }

    /** @return Collection<int, Payment> the payments recorded for one gateway payment (one per line of its notice) */
    public function payments(string $method, string $reference): Collection
    {
        return Payment::query()->where('method', $method)->where('reference', $reference)
            ->where('amount_cents', '>', 0)->whereNull('refund_of')->orderBy('id')->get();
    }

    /**
     * Runs $then with the state row and the payments, under the lock of their services: Refund payment holds the
     * same lock while it refunds at the provider, so the provider's report of that refund waits for it to be recorded.
     * A reference with no payment here (another sale on the same account, or one never recorded) is left alone.
     *
     * @param  callable(ProviderPaymentState, Collection<int, Payment>): void  $then
     */
    private function locked(string $method, string $reference, string $currency, callable $then): void
    {
        if ($method === '' || $reference === '' || $this->payments($method, $reference)->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($method, $reference, $currency, $then) {
            $payments = $this->payments($method, $reference);
            Service::query()->whereIn('id', $payments->pluck('service_id')->unique())->orderBy('id')->lockForUpdate()->get();

            $state = ProviderPaymentState::query()->createOrFirst(
                ['method' => $method, 'reference' => $reference],
                ['currency' => strtoupper($payments->first()->currency ?: $currency)],
            );
            $state = ProviderPaymentState::query()->lockForUpdate()->findOrFail($state->id);

            $then($state, $this->payments($method, $reference)->load('service'));
        });
    }

    /**
     * What GaiaCP has recorded as given back of these payments (Refund payment, any way).
     *
     * @param  Collection<int, Payment>  $payments
     */
    private function refundedHere(Collection $payments): int
    {
        return (int) -Payment::query()->whereIn('refund_of', $payments->modelKeys())->sum('amount_cents');
    }

    /** @param  Collection<int, Payment>  $payments */
    private function notice(Collection $payments): string
    {
        $number = InvoiceItem::query()->whereIn('payment_id', $payments->modelKeys())->with('invoice')->first()?->invoice?->number;

        return $number ? " (payment notice {$number})" : '';
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return array<string, mixed>
     */
    private function properties(string $method, string $reference, Collection $payments): array
    {
        $item = InvoiceItem::query()->whereIn('payment_id', $payments->modelKeys())->first();

        return [
            'method' => $method,
            'reference' => $reference,
            'payment_id' => $payments->first()->id,
            'payment_ids' => $payments->modelKeys(),
            'invoice_id' => $item?->invoice_id,
        ];
    }

    /** The gateway's name for the log, which stays in English. */
    private function provider(string $method): string
    {
        $locale = app()->getLocale();
        app()->setLocale('en');

        try {
            return (string) PaymentMethods::label($method);
        } finally {
            app()->setLocale($locale);
        }
    }
}
