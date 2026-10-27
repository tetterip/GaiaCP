<?php

namespace App\Models;

use App\Enums\CreditType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a client's credit ledger: what came in (+) or went out (-) and the balance after it. Written only by
 * App\Credit\ClientCredit, never edited.
 *
 * @property CarbonImmutable $created_at
 */
#[Fillable(['client_id', 'type', 'amount_cents', 'balance_cents', 'currency', 'description', 'invoice_id', 'payment_id', 'method', 'reference', 'user_id', 'created_at'])]
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => CreditType::class,
            'amount_cents' => 'integer',
            'balance_cents' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
