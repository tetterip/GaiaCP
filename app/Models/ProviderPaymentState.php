<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a payment gateway said about one of its payments after it was recorded (App\Payments\ProviderReports): the
 * total refunded at the provider and the latest chargeback. Keyed like the payments it covers: `method` + `reference`.
 */
#[Fillable(['method', 'reference', 'currency', 'refunded_cents', 'dispute_id', 'dispute_status', 'dispute_cents', 'dispute_reason'])]
class ProviderPaymentState extends Model
{
    use StoresTimesInUtc;

    public const DISPUTE_OPEN = 'open';

    public const DISPUTE_WON = 'won';

    public const DISPUTE_LOST = 'lost';

    protected $attributes = [
        'refunded_cents' => 0,
    ];

    protected function casts(): array
    {
        return [
            'refunded_cents' => 'integer',
            'dispute_cents' => 'integer',
        ];
    }

    public static function for(Payment $payment): ?self
    {
        if (blank($payment->method) || blank($payment->reference)) {
            return null;
        }

        return self::query()->where('method', $payment->method)->where('reference', $payment->reference)->first();
    }

    /** What the gateway said about the payment, for the payments tables: a chargeback, or a refund made there. */
    public static function note(Payment $payment): ?string
    {
        if ($payment->amount_cents <= 0 || $payment->refund_of !== null || ! ($state = self::for($payment))) {
            return null;
        }

        return match ($state->dispute_status) {
            self::DISPUTE_OPEN => __('Chargeback open'),
            self::DISPUTE_LOST => __('Chargeback lost'),
            self::DISPUTE_WON => __('Chargeback won'),
            default => $state->refunded_cents > 0
                ? __('Refunded at the provider: :amount', ['amount' => Money::format($state->refunded_cents, $state->currency)])
                : null,
        };
    }
}
