<?php

namespace App\Models;

use App\Support\Tax;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An invoice (Settings → Invoices): issued by App\Invoicing\IssueInvoice from a payment notice once it is paid, with
 * its own number from App\Invoicing\InvoiceNumbers. Unlike a notice it is never changed or deleted: the seller, the
 * client's details, the lines and the tax are copies taken when it was issued.
 *
 * @property CarbonImmutable $date
 * @property CarbonImmutable|null $paid_on
 */
#[Fillable([
    'type', 'number', 'invoice_id', 'client_id', 'date', 'paid_on', 'currency', 'seller', 'bill_to',
    'subtotal_cents', 'tax_name', 'tax_rate', 'tax_inclusive', 'tax_cents', 'tax_note', 'total_cents', 'payment_methods',
])]
class TaxInvoice extends Model
{
    public const INVOICE = 'invoice';

    protected $attributes = [
        'type' => self::INVOICE,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'seller' => 'array',
            'bill_to' => 'array',
            'subtotal_cents' => 'integer',
            'tax_rate' => 'float',
            'tax_inclusive' => 'boolean',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An issued invoice can\'t be changed.'));
        static::deleting(fn () => throw new LogicException('An issued invoice can\'t be deleted.'));
    }

    /** "VAT 24%", or null without tax. */
    public function taxLabel(): ?string
    {
        return $this->tax_rate ? $this->tax_name.' '.Tax::formatRate($this->tax_rate) : null;
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Invoice, $this> the payment notice it was issued from */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** @return HasMany<TaxInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TaxInvoiceItem::class);
    }
}
