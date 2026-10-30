<?php

namespace App\Models;

use App\Payments\PaymentMethods;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An invoice (Settings → Invoices): issued by App\Invoicing\IssueInvoice from a payment notice once it is paid, with
 * its own number from App\Invoicing\InvoiceNumbers. Unlike a notice it is never changed or deleted: the seller, the
 * client's details, the lines and the tax are copies taken when it was issued. A credit note (type credit_note, made by
 * App\Invoicing\IssueCreditNote) undoes some or all lines of one, in its own series; its amounts are kept positive.
 *
 * @property CarbonImmutable $date
 * @property CarbonImmutable|null $paid_on
 */
#[Fillable([
    'type', 'number', 'invoice_id', 'credited_invoice_id', 'client_id', 'date', 'paid_on', 'currency', 'seller', 'bill_to',
    'subtotal_cents', 'tax_name', 'tax_rate', 'tax_inclusive', 'tax_cents', 'tax_note', 'reason', 'total_cents', 'payment_methods',
])]
class TaxInvoice extends Model
{
    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

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

    public function isCreditNote(): bool
    {
        return $this->type === self::CREDIT_NOTE;
    }

    /** "Invoice" or "Credit note", in the current language. */
    public function typeLabel(): string
    {
        return $this->isCreditNote() ? __('Credit note') : __('Invoice');
    }

    /** The total as it counts towards turnover: negative for a credit note. */
    public function signedTotalCents(): int
    {
        return $this->isCreditNote() ? -$this->total_cents : $this->total_cents;
    }

    /** SQL for the signed total of each row, for sums over invoices and credit notes together. */
    public static function signedTotalSql(string $column = 'total_cents'): string
    {
        return "case when type = '".self::CREDIT_NOTE."' then -{$column} else {$column} end";
    }

    /** @return Collection<int, TaxInvoiceItem> the lines of an invoice that no credit note has credited yet */
    public function creditableItems(): Collection
    {
        if ($this->isCreditNote()) {
            return new Collection;
        }

        $credited = TaxInvoiceItem::query()
            ->whereIn('tax_invoice_id', $this->creditNotes()->select('id'))
            ->pluck('credited_item_id');

        return $this->items()->whereNotIn('id', $credited->filter())->orderBy('id')->get();
    }

    /** Whether credit notes have undone every line of this invoice. */
    public function fullyCredited(): bool
    {
        return ! $this->isCreditNote() && $this->creditNotes()->exists() && $this->creditableItems()->isEmpty();
    }

    /** "Bank transfer, Account credit": how it was paid, in the current language; null when unknown. */
    public function paymentMethods(): ?string
    {
        return filled($this->payment_methods)
            ? collect(explode(',', $this->payment_methods))->map(fn (string $key) => PaymentMethods::label($key) ?? $key)->join(', ')
            : null;
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

    /** @return BelongsTo<TaxInvoice, $this> the invoice a credit note credits */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credited_invoice_id');
    }

    /** @return HasMany<TaxInvoice, $this> the credit notes of an invoice */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credited_invoice_id');
    }

    /** @return HasMany<TaxInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TaxInvoiceItem::class);
    }
}
