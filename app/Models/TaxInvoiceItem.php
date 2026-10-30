<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of an invoice, copied from its payment notice line when the invoice was issued, or of a credit note, copied
 * from the invoice line it credits (credited_item_id); never changed after.
 *
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 */
#[Fillable(['tax_invoice_id', 'credited_item_id', 'service_id', 'description', 'period_start', 'period_end', 'amount_cents', 'tax_cents', 'total_cents'])]
class TaxInvoiceItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'amount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An issued invoice can\'t be changed.'));
        static::deleting(fn () => throw new LogicException('An issued invoice can\'t be deleted.'));
    }

    /** @return BelongsTo<TaxInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'tax_invoice_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
