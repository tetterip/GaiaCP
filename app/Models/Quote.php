<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\QuoteStatus;
use App\Support\Locales;
use App\Support\Money;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A quote (Billing → Quotes, App\Actions\Quotes) to a lead or a client: the items of an order as New order makes them,
 * with the prices offered (kept on renewals once accepted), one-off lines billed with the first payment, valid until a
 * date. Numbered QT-2026-0001. Its page (/quote/{token}, no login) shows it and takes the acceptance, which places the
 * order; the lead becomes a client then.
 *
 * @property CarbonImmutable $valid_until
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $declined_at
 */
#[Fillable(['number', 'lead_id', 'client_id', 'status', 'currency', 'locale', 'items', 'extras', 'intro', 'footer', 'valid_until', 'token', 'sent_at', 'accepted_at', 'accepted_ip', 'terms_accepted_at', 'terms_version', 'declined_at', 'decline_reason', 'order_id', 'notes', 'user_id'])]
class Quote extends Model
{
    public const NUMBER_PREFIX = 'QT';

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'items' => 'array',
            'extras' => 'array',
            'valid_until' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $quote) {
            $quote->number ??= self::nextNumber(now());
            $quote->token ??= Str::random(48);
        });
    }

    public static function nextNumber(CarbonInterface $date): string
    {
        $prefix = self::NUMBER_PREFIX.'-'.$date->year.'-';

        $last = self::query()->where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [QuoteStatus::Draft, QuoteStatus::Sent]);
    }

    /** Whether its link still takes an answer: sent and not past its date. */
    public function acceptable(): bool
    {
        return $this->status === QuoteStatus::Sent && ! $this->valid_until->lt(today());
    }

    /** Who it is for: the client, else the lead. */
    public function recipientName(): string
    {
        return $this->client?->displayName() ?? $this->lead?->displayName() ?? '';
    }

    public function recipientEmail(): ?string
    {
        return $this->client?->email ?? $this->lead?->email;
    }

    /**
     * The client it is for, or one made up from the lead (not saved) for the email and the tax rule.
     */
    public function recipient(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        return new Client(array_filter([
            'first_name' => $this->lead?->first_name,
            'last_name' => (string) $this->lead?->last_name,
            'company' => $this->lead?->company,
            'email' => $this->lead?->email,
            'country' => $this->lead?->country,
            'locale' => $this->locale,
        ], fn ($value) => $value !== null));
    }

    public function preferredLocale(): string
    {
        return Locales::normalize($this->locale);
    }

    /**
     * Its lines as the page and the email show them: each item's first payment (with what it renews at), then the
     * one-off lines.
     *
     * @return list<array{description: string, cycle: ?string, first_cents: int, renews_cents: ?int}>
     */
    public function lines(): array
    {
        $lines = collect($this->items ?? [])->map(function (array $item) {
            $price = (int) ($item['price_cents'] ?? 0);
            $first = filled($item['first_payment_cents'] ?? null) ? (int) $item['first_payment_cents'] : $price;
            $cycle = BillingCycle::tryFrom((string) ($item['billing_cycle'] ?? ''));
            $label = ($item['type'] ?? null) === 'domain'
                // Joined, not trimmed: trim() works on bytes and would cut a Greek letter ending in the byte of '·'.
                ? implode(' · ', array_filter([$item['domain'] ?? null, DomainRegistrationType::tryFrom((string) ($item['registration_type'] ?? ''))?->getLabel()]))
                : trim(($item['label'] ?? '').' '.($item['domain'] ?? ''));

            return [
                'description' => $label,
                'cycle' => $cycle?->getLabel(),
                'first_cents' => $first,
                'renews_cents' => $price,
            ];
        });

        foreach ($this->extras ?? [] as $extra) {
            $lines->push(['description' => (string) $extra['description'], 'cycle' => null, 'first_cents' => (int) $extra['amount_cents'], 'renews_cents' => null]);
        }

        return $lines->values()->all();
    }

    /** @return array{net: int, tax: int, total: int, rule: array{name: string, rate: float}|null} the first payment, with tax where it applies */
    public function totals(): array
    {
        return Tax::totals($this->recipient(), array_column($this->lines(), 'first_cents'));
    }

    public function totalText(): string
    {
        return Tax::describe($this->totals(), $this->currency);
    }

    public function money(int $cents): string
    {
        return Money::format($cents, $this->currency);
    }

    public function url(): string
    {
        return route('quote.show', ['token' => $this->token]);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
