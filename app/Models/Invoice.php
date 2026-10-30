<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Invoicing\IssueInvoice;
use App\Support\ClientFields;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Throwable;

/**
 * A payment notice (proforma): what a client owes on one due date, grouped from the services due
 * then. Not a legal invoice; those are issued in the accounting tool. Created by GenerateInvoices,
 * paid when every item's service has a payment for that due date (RecordPayment marks the item).
 *
 * @property CarbonImmutable $date
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $paid_on
 * @property CarbonImmutable|null $emailed_at
 */
#[Fillable([
    'number', 'client_id', 'bill_to', 'order_id', 'status', 'date', 'due_date', 'currency',
    'subtotal_cents', 'tax_name', 'tax_rate', 'tax_inclusive', 'tax_cents', 'tax_note', 'total_cents',
    'paid_on', 'emailed_at', 'notes', 'credit',
])]
class Invoice extends Model
{
    public const NUMBER_PREFIX = 'PF';

    /** The client's details a notice keeps from the day it is issued (`bill_to`), shown as "For". */
    public const BILL_TO = ['company', 'first_name', 'last_name', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'vat_number', 'tax_office'];

    protected $attributes = [
        'status' => 'unpaid',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'emailed_at' => 'immutable_datetime',
            'subtotal_cents' => 'integer',
            'tax_rate' => 'float',
            'tax_inclusive' => 'boolean',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'credit' => 'boolean', // an "Add funds" notice (App\Credit\AddFunds)
            'bill_to' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if ($invoice->bill_to === null && $invoice->client_id !== null) {
                $invoice->bill_to = static::billToOf($invoice->client ?? Client::find($invoice->client_id));
            }

            // Why no tax although a rule applies (e.g. the VIES plugin's reverse charge), kept like the rate
            if ($invoice->tax_note === null && ! $invoice->credit && ! $invoice->tax_cents && $invoice->client_id !== null
                && ($client = $invoice->client ?? Client::find($invoice->client_id))) {
                $invoice->tax_note = Tax::exemptionFor($client);
            }
        });
    }

    /** @return array<string, mixed> */
    public static function billToOf(?Client $client): array
    {
        if ($client === null) {
            return [];
        }

        // Plus the custom fields shown on notices (Settings → Client fields), as they read that day
        return $client->only(self::BILL_TO) + array_filter(['custom' => ClientFields::forNotice($client)]);
    }

    /** The details on the notice, which may since differ from the client's (billToChanged()). */
    public function billTo(): object
    {
        return (object) array_merge(array_fill_keys([...self::BILL_TO, 'custom'], null), $this->bill_to ?? static::billToOf($this->client));
    }

    public function billToChanged(): bool
    {
        return static::billToOf($this->client) != array_merge(array_fill_keys(self::BILL_TO, null), $this->bill_to ?? []);
    }

    /** Take the client's current details, for an open notice issued before the client changed them. */
    public function refreshBillTo(): void
    {
        $this->update(['bill_to' => static::billToOf($this->client)]);
        ActivityLog::record('invoice.bill_to_updated', "Payment notice {$this->number} now shows the client's current details", client: $this->client);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Order, $this> the order whose first payment it asks for */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasOne<TaxInvoice, $this> the invoice issued once it was paid (Settings → Invoices) */
    public function taxInvoice(): HasOne
    {
        return $this->hasOne(TaxInvoice::class)->where('type', TaxInvoice::INVOICE);
    }

    /** @return HasMany<InvoiceReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(InvoiceReminder::class);
    }

    /** @param Builder<Invoice> $query */
    public function scopeUnpaid(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Unpaid);
    }

    public function isOverdue(?CarbonInterface $today = null): bool
    {
        return $this->status === InvoiceStatus::Unpaid
            && $this->due_date->lessThan(CarbonImmutable::parse($today ?? now())->startOfDay());
    }

    /** The next free number for this year: PF-2026-0001. */
    public static function nextNumber(CarbonInterface $date): string
    {
        $prefix = self::NUMBER_PREFIX.'-'.$date->year.'-';

        $last = self::query()->where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** "ΦΠΑ 17%", or null without tax. */
    public function taxLabel(): ?string
    {
        return $this->tax_rate ? $this->tax_name.' '.Tax::formatRate($this->tax_rate) : null;
    }

    /**
     * A service set to end at its due date: takes its unpaid lines for cycles not started yet (with their late fees)
     * off its unpaid notices, so they get no more reminders and can't be paid; an overdue cycle stays owed. A service
     * terminated or cancelled now (`$overdueToo`) owes none of its unpaid cycles any more. A notice left with no lines
     * is cancelled, one left with only paid lines is paid.
     */
    public static function withdraw(Service $service, bool $overdueToo = false, string $reason = 'it ends at its due date'): void
    {
        $items = InvoiceItem::query()->where('service_id', $service->id)->whereNull('payment_id')
            // An upgrade's difference is for days already started: owed unless the service ends now.
            ->when(! $overdueToo, fn (Builder $q) => $q->whereDate('due_date', '>=', today())->forCycle())
            ->whereHas('invoice', fn (Builder $q) => $q->unpaid())->with('invoice')->get();

        foreach ($items->groupBy('invoice_id') as $lines) {
            $invoice = $lines->first()->invoice;
            InvoiceItem::query()->whereKey($lines->modelKeys())->delete();
            $rest = $invoice->items()->get();

            if ($rest->isEmpty()) {
                $invoice->update(['status' => InvoiceStatus::Cancelled]);
                ActivityLog::record('invoice.cancelled', "Payment notice {$invoice->number} cancelled: {$service->label}: {$reason}", $service, properties: ['invoice_id' => $invoice->id]);

                continue;
            }

            $invoice->update([
                'subtotal_cents' => $rest->sum('total_cents') - $rest->sum('tax_cents'),
                'tax_cents' => $rest->sum('tax_cents'),
                'total_cents' => $rest->sum('total_cents'),
            ]);
            ActivityLog::record('invoice.line_removed', "{$service->label} taken off payment notice {$invoice->number}: {$reason}", $service);
            $invoice->settle(today());
        }
    }

    /**
     * Taxes it again under another rule (null = without tax, e.g. the admin waives it on an order): each line from its
     * amount, as GenerateInvoices does, and the totals summed again. Unpaid notices only.
     *
     * @param  array{name: string, rate: float}|null  $rule
     */
    public function retax(?array $rule, bool $inclusive): void
    {
        foreach ($this->items()->get() as $item) {
            $tax = Tax::apply($item->amount_cents, $rule['rate'] ?? null, $inclusive);
            $item->update(['tax_cents' => $tax['tax'], 'total_cents' => $tax['total']]);
        }

        $items = $this->items()->get();
        $this->update([
            'tax_name' => $rule['name'] ?? null,
            'tax_rate' => $rule['rate'] ?? null,
            'tax_inclusive' => $rule !== null && $inclusive,
            'tax_note' => null,
            'subtotal_cents' => $items->sum('total_cents') - $items->sum('tax_cents'),
            'tax_cents' => $items->sum('tax_cents'),
            'total_cents' => $items->sum('total_cents'),
        ]);
    }

    /** Mark paid once every item is; called after an item's payment was set. */
    public function settle(CarbonInterface $paidOn): void
    {
        if ($this->status === InvoiceStatus::Unpaid && ! $this->items()->unpaid()->exists()) {
            $this->update(['status' => InvoiceStatus::Paid, 'paid_on' => $paidOn]);

            ActivityLog::record('invoice.paid', "Payment notice {$this->number} paid", client: $this->client, properties: ['invoice_id' => $this->id]);

            if (IssueInvoice::enabled()) {
                try {
                    app(IssueInvoice::class)->handle($this);
                } catch (Throwable $e) {
                    // The payment stands; Issue invoice on the notice tries again.
                    report($e);
                    ActivityLog::record('tax_invoice.failed', "No invoice issued for payment notice {$this->number}: {$e->getMessage()}", client: $this->client, properties: ['invoice_id' => $this->id]);
                }
            }
        }
    }
}
