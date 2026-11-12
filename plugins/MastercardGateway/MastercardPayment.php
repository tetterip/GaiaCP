<?php

namespace Plugins\MastercardGateway;

use App\Models\Concerns\StoresTimesInUtc;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Mastercard Gateway card payment started for a payment notice (MastercardPayments), named at the gateway by
 * `order_id`. Its status moves open → processing → paid once (or failed when the card was declined, expired when the
 * client never finished, unmatched when the gateway took the money but it could not be recorded), so a payment
 * looked up twice is recorded once.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $order_id
 * @property bool $live
 * @property int $amount_cents
 * @property string $currency
 * @property string|null $session_id
 * @property string|null $success_indicator
 * @property string $status
 * @property string|null $transaction_id
 * @property Carbon|null $paid_at
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Invoice|null $invoice
 */
#[Fillable(['invoice_id', 'order_id', 'live', 'amount_cents', 'currency', 'session_id', 'success_indicator', 'status', 'transaction_id', 'paid_at', 'error'])]
#[Hidden(['session_id', 'success_indicator'])]
class MastercardPayment extends Model
{
    use StoresTimesInUtc;

    protected $table = 'mastercard_payments';

    public const OPEN = 'open';

    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    /** The gateway declined the card (the client may still pay on the same page, so a later capture is recorded). */
    public const FAILED = 'failed';

    /** The client never finished on the gateway's page (MastercardPayments::recheck()). */
    public const EXPIRED = 'expired';

    /** Paid at the gateway, not recorded here: `error` says why, an admin records or refunds it. */
    public const UNMATCHED = 'unmatched';

    protected $attributes = [
        'status' => self::OPEN,
    ];

    protected function casts(): array
    {
        return [
            'live' => 'boolean',
            'amount_cents' => 'integer',
            'success_indicator' => 'encrypted',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
