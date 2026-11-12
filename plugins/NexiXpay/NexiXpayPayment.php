<?php

namespace Plugins\NexiXpay;

use App\Models\Concerns\StoresTimesInUtc;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Nexi XPay card payment started for a payment notice (NexiXpayPayments), named at Nexi by `order_id`. Its status
 * moves open → processing → paid once (or pending while Nexi has no final outcome, cancelled, failed, expired when the
 * client never finished, unmatched when Nexi took the money but it could not be recorded), so a payment Nexi reports
 * twice is recorded once.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $order_id
 * @property bool $live
 * @property int $amount_cents
 * @property string $currency
 * @property string|null $security_token
 * @property string $status
 * @property string|null $operation_id
 * @property Carbon|null $paid_at
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Invoice|null $invoice
 */
#[Fillable(['invoice_id', 'order_id', 'live', 'amount_cents', 'currency', 'security_token', 'status', 'operation_id', 'paid_at', 'error'])]
#[Hidden(['security_token'])]
class NexiXpayPayment extends Model
{
    use StoresTimesInUtc;

    protected $table = 'nexi_xpay_payments';

    public const OPEN = 'open';

    public const PROCESSING = 'processing';

    /** Nexi has no final outcome yet (operationResult PENDING): looked up again every hour. */
    public const PENDING = 'pending';

    public const PAID = 'paid';

    /** The client pressed Cancel on Nexi's page. */
    public const CANCELLED = 'cancelled';

    /** Nexi refused the card, or 3-D Secure failed. */
    public const FAILED = 'failed';

    /** The client never finished on Nexi's page (NexiXpayPayments::recheck()). */
    public const EXPIRED = 'expired';

    /** Paid at Nexi, not recorded here: `error` says why, an admin records or refunds it. */
    public const UNMATCHED = 'unmatched';

    protected $attributes = [
        'status' => self::OPEN,
    ];

    protected function casts(): array
    {
        return [
            'live' => 'boolean',
            'amount_cents' => 'integer',
            'security_token' => 'encrypted',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
