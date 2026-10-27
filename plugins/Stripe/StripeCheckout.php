<?php

namespace Plugins\Stripe;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Stripe Checkout page opened for a payment notice (StripeCheckouts). Its status moves open → processing → paid
 * once (or unmatched when the payment could not be recorded), so a payment Stripe reports twice is recorded once.
 *
 * @property Carbon|null $paid_at
 */
#[Fillable(['invoice_id', 'session_id', 'livemode', 'amount_cents', 'currency', 'status', 'payment_intent', 'paid_at', 'error'])]
class StripeCheckout extends Model
{
    public const OPEN = 'open';

    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const FAILED = 'failed';

    /** Paid at Stripe, not recorded here: `error` says why, an admin records or refunds it. */
    public const UNMATCHED = 'unmatched';

    protected $attributes = [
        'status' => self::OPEN,
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
