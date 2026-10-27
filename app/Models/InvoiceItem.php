<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service's cycle on a payment notice: the service due on `due_date`, covering it until
 * `period_end`. `payment_id` is the payment that paid that cycle. A line with a `fee` ("grace" or
 * "redemption") is a domain's late renewal fee for that cycle: it is paid with the cycle's line. An "upgrade" line is the
 * difference of a change of product (App\Actions\ChangeProduct) from `due_date` to `period_end` (the service's due date):
 * paid on its own, without moving the due date. A `credit` line has no
 * service: it is an "Add funds" amount, paid into the client's balance (`credit_transaction_id`).
 *
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable $period_end
 */
#[Fillable(['invoice_id', 'service_id', 'fee', 'description', 'due_date', 'period_end', 'amount_cents', 'tax_cents', 'total_cents', 'payment_id', 'credit', 'credit_transaction_id'])]
class InvoiceItem extends Model
{
    public const UPGRADE = 'upgrade';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'period_end' => 'immutable_date',
            'amount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'credit' => 'boolean',
        ];
    }

    /**
     * Lines still owed: a service's cycle (or late fee) with no payment, or the "Add funds" line of a notice not paid
     * into the balance yet. A line whose service is gone has nothing to pay through.
     *
     * @param  Builder<InvoiceItem>  $query
     */
    public function scopeUnpaid(Builder $query): void
    {
        $query->whereNull('payment_id')->whereNull('credit_transaction_id')
            ->where(fn (Builder $q) => $q->whereNotNull('service_id')->orWhere('credit', true));
    }

    /**
     * Lines of a service's cycle or its late fees, which a payment for that cycle pays: not an upgrade's difference.
     *
     * @param  Builder<InvoiceItem>  $query
     */
    public function scopeForCycle(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('fee')->orWhere('fee', '!=', self::UPGRADE));
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
