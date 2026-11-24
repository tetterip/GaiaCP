<?php

namespace App\Actions;

use App\Credit\ClientCredit;
use App\Enums\CreditType;
use App\Invoicing\IssueCreditNote;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Payments\RefundsAtProvider;
use App\Plugins\PluginManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gives a payment's money back, any payment, not only the latest (ReversePayment undoes one recorded by mistake): a
 * refund is a payment of minus its amount on the day it was given back (`refund_of`), so income reports count it then,
 * and the payment stays as it was. TO_CREDIT puts the money in the client's balance (always, for a payment made from
 * credit); ELSEWHERE records that it was paid back outside GaiaCP (nothing is sent to Stripe, PayPal or a bank);
 * AT_PROVIDER first gives it back at the gateway it was paid through (a PaymentModule that RefundsAtProvider) and
 * records the refund only once the gateway took it. An invoice issued for what it paid gets a credit note for those lines. The service's due date and status stay: cancel or
 * terminate it on its own if it ends.
 */
class RefundPayment
{
    public const TO_CREDIT = 'credit';

    public const ELSEWHERE = 'elsewhere';

    /** Given back at the gateway it was paid through (Stripe, PayPal), then recorded. */
    public const AT_PROVIDER = 'provider';

    public function __construct(private ClientCredit $credit, private IssueCreditNote $creditNotes) {}

    /** A payment of money (not a refund itself) that has not been refunded yet. */
    public static function canRefund(Payment $payment): bool
    {
        return $payment->amount_cents > 0 && $payment->refund_of === null
            && Payment::query()->where('refund_of', $payment->id)->doesntExist();
    }

    /** The gateway that can give this payment back itself, if any (its plugin on and set up). */
    public static function provider(Payment $payment): ?RefundsAtProvider
    {
        $module = filled($payment->method) ? app(PluginManager::class)->paymentModules()->get($payment->method) : null;

        return $module instanceof RefundsAtProvider && $module->canRefundAtProvider($payment) ? $module : null;
    }

    /** @throws RuntimeException refunded already, or the gateway refused (nothing is recorded then) */
    public function handle(Payment $payment, string $to, ?CarbonImmutable $on = null, ?string $notes = null): Payment
    {
        $on ??= CarbonImmutable::today();
        $toCredit = $to === self::TO_CREDIT || $payment->method === ClientCredit::METHOD;
        $atProvider = ! $toCredit && $to === self::AT_PROVIDER;

        return DB::transaction(function () use ($payment, $toCredit, $atProvider, $on, $notes) {
            // The service row is the lock: two refunds of the same payment at once give it back once.
            $service = Service::query()->lockForUpdate()->findOrFail($payment->service_id);

            if (! self::canRefund($payment->fresh())) {
                throw new RuntimeException('This payment was refunded already.');
            }

            // At the gateway first, under the service's lock: its report of this refund (webhook) waits for the row
            // below, so it never rings the bell as a refund made outside GaiaCP. A gateway that refuses leaves nothing.
            $providerRefund = null;
            if ($atProvider) {
                $provider = self::provider($payment) ?? throw new RuntimeException(__('This payment can\'t be refunded at the payment provider.'));
                $providerRefund = $provider->refundAtProvider($payment);
                $notes = trim(__('Refund at the payment provider: :id', ['id' => $providerRefund])."\n".$notes);
            }

            $refund = Payment::create([
                'service_id' => $service->id,
                'client_id' => $payment->client_id,
                'refund_of' => $payment->id,
                'amount_cents' => -$payment->amount_cents,
                'currency' => $payment->currency,
                'paid_on' => $on,
                'method' => $toCredit ? ClientCredit::METHOD : $payment->method,
                'reference' => $payment->reference,
                'due_date_before' => $service->next_due_date,
                'due_date_after' => $service->next_due_date,
                'recorded_by' => ApiKey::actingUserId(),
                'notes' => $notes,
            ]);

            if ($toCredit && ($client = $payment->client ?? $service->client)) {
                $this->credit->record($client, CreditType::Refund, $payment->amount_cents, "Payment refunded: {$service->label}", ['payment_id' => $refund->id]);
            }

            // The invoice of what it paid: a credit note for its lines of this service.
            foreach (InvoiceItem::query()->where('payment_id', $payment->id)->with('invoice.taxInvoice')->get()->pluck('invoice')->unique('id') as $notice) {
                $lines = $notice->taxInvoice?->creditableItems()->where('service_id', $service->id);

                if ($lines !== null && $lines->isNotEmpty()) {
                    $this->creditNotes->handle($notice->taxInvoice, $lines->pluck('id')->all(), IssueCreditNote::PAYMENT_REFUNDED);
                }
            }

            ActivityLog::record('payment.refunded', sprintf(
                'Payment of %s of %s refunded %s',
                Money::format($payment->amount_cents, $payment->currency),
                $payment->paid_on->toDateString(),
                $toCredit ? 'to credit' : ($atProvider ? 'at '.$payment->method.' ('.$providerRefund.')' : 'outside GaiaCP'),
            ), $service, properties: array_filter(['payment_id' => $payment->id, 'refund_id' => $refund->id, 'provider_refund' => $providerRefund]));

            return $refund;
        });
    }
}
