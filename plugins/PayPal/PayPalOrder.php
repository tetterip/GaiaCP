<?php

namespace Plugins\PayPal;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A PayPal order opened for a payment notice (PayPalPayments). Its status moves open → processing → paid once (or
 * pending while the payment clears, cancelled when the notice changed before the money was taken, unmatched when it
 * was taken but could not be recorded), so a payment PayPal reports twice is taken and recorded once.
 *
 * @property Carbon|null $paid_at
 */
#[Fillable(['invoice_id', 'order_id', 'live', 'amount_cents', 'currency', 'status', 'capture_id', 'paid_at', 'error'])]
class PayPalOrder extends Model
{
    protected $table = 'paypal_orders';

    public const OPEN = 'open';

    public const PROCESSING = 'processing';

    /** Taken, but PayPal is still clearing it (an eCheck, a review): recorded when PayPal completes it. */
    public const PENDING = 'pending';

    public const PAID = 'paid';

    /** Approved by the client, but not taken: the notice no longer asked for that amount. */
    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    /** Taken at PayPal, not recorded here: `error` says why, an admin records or refunds it. */
    public const UNMATCHED = 'unmatched';

    protected $attributes = [
        'status' => self::OPEN,
    ];

    protected function casts(): array
    {
        return [
            'live' => 'boolean',
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
