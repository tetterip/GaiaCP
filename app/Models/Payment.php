<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_id', 'client_id', 'amount_cents', 'currency', 'paid_on', 'method', 'reference',
    'due_date_before', 'due_date_after', 'recorded_by', 'notes',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

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

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
