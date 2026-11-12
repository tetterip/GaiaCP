<?php

namespace Plugins\AlphaBank;

use App\Models\Concerns\StoresTimesInUtc;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Alpha Bank card payment started for a payment notice (AlphaBankPayments), named at the bank by `reference` (the
 * orderid). Its status moves open → processing → paid once (or cancelled, failed, expired when no answer came, unmatched
 * when the bank took the money but it could not be recorded), so an answer the bank sends twice is recorded once.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $reference
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property string|null $transaction_id
 * @property bool $live
 * @property string|null $payment_reference
 * @property string|null $pay_method
 * @property string|null $error
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Invoice|null $invoice
 */
#[Fillable(['invoice_id', 'reference', 'live', 'amount_cents', 'currency', 'status', 'transaction_id', 'payment_reference', 'pay_method', 'paid_at', 'error'])]
class AlphaBankPayment extends Model
{
    use StoresTimesInUtc;

    protected $table = 'alphabank_payments';

    public const OPEN = 'open';

    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    /** The client pressed Cancel on the bank's page. */
    public const CANCELLED = 'cancelled';

    /** The bank refused the card, or the payment ended in an error. */
    public const FAILED = 'failed';

    /** No answer from the bank within a few hours: the client left the bank's page (AlphaBankPayments::expire()). */
    public const EXPIRED = 'expired';

    /** Paid at the bank, not recorded here: `error` says why, an admin records or refunds it. */
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
