<?php

namespace App\Models;

use App\Models\Concerns\StoresTimesInUtc;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'service_id', 'client_id', 'refund_of', 'amount_cents', 'currency', 'paid_on', 'method', 'reference',
    'due_date_before', 'due_date_after', 'recorded_by', 'notes',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use StoresTimesInUtc;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_on' => 'immutable_date',
            'due_date_before' => 'immutable_date',
            'due_date_after' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        // Who paid: the service's client at the time, kept if the service later moves to another client.
        static::creating(function (Payment $payment) {
            $payment->client_id ??= $payment->service?->client_id;
        });
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Payment, $this> for a refund (App\Actions\RefundPayment): the payment it gave back */
    public function refunded(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_of');
    }

    /** @return HasMany<Payment, $this> the refund that gave it back, if any */
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_of');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
