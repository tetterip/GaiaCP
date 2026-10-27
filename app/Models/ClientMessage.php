<?php

namespace App\Models;

use App\Enums\ClientMessageKind;
use App\Enums\ClientStatus;
use App\Enums\ServiceStatus;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Clients → Messages to clients: a text per language for the active clients its filters match, emailed a few a
 * minute (App\Actions\SendClientMessages, one recipient row each) and/or shown on client area Home between its dates.
 *
 * Filters (all optional, combined): servers, products, tlds and types narrow to clients with an active or suspended
 * service matching every one given; locales to clients with that email language.
 *
 * @property array<string, array{subject: ?string, body: ?string}> $texts
 * @property array{servers?: list<int>, products?: list<int>, tlds?: list<string>, types?: list<string>, locales?: list<string>}|null $filters
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable([
    'kind', 'texts', 'filters', 'send_email', 'show_in_client_area', 'show_from', 'show_until', 'queued_at', 'finished_at', 'created_by',
])]
class ClientMessage extends Model
{
    protected $attributes = [
        'kind' => 'info',
        'send_email' => true,
        'show_in_client_area' => false,
    ];

    protected function casts(): array
    {
        return [
            'kind' => ClientMessageKind::class,
            'texts' => 'array',
            'filters' => 'array',
            'send_email' => 'boolean',
            'show_in_client_area' => 'boolean',
            'show_from' => 'immutable_date',
            'show_until' => 'immutable_date',
            'queued_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ClientMessageRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(ClientMessageRecipient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The subject and body in a language, else in the default one.
     *
     * @return array{subject: string, body: string}
     */
    public function text(?string $locale = null): array
    {
        $texts = $this->texts ?? [];
        $own = $texts[Locales::normalize($locale)] ?? null;
        $text = filled($own['subject'] ?? null) && filled($own['body'] ?? null) ? $own : ($texts[Locales::default()] ?? []);

        return ['subject' => (string) ($text['subject'] ?? ''), 'body' => (string) ($text['body'] ?? '')];
    }

    public function subject(): string
    {
        return $this->text(Locales::default())['subject'];
    }

    /** @return Builder<Client> the active clients the filters match */
    public function clients(): Builder
    {
        $filters = $this->filters ?? [];
        $servers = array_filter((array) ($filters['servers'] ?? []));
        $products = array_filter((array) ($filters['products'] ?? []));
        $tlds = array_values(array_filter(array_map(fn ($tld) => '.'.ltrim(mb_strtolower(trim((string) $tld)), '.'), (array) ($filters['tlds'] ?? [])), fn ($tld) => $tld !== '.'));
        $types = array_filter((array) ($filters['types'] ?? []));
        $locales = array_filter((array) ($filters['locales'] ?? []));

        return Client::query()
            ->where('status', ClientStatus::Active)
            ->when($locales !== [], fn (Builder $q) => $q->where(function (Builder $q) use ($locales) {
                $q->whereIn('locale', $locales);
                // No language of their own = the default one
                if (in_array(Locales::default(), $locales, true)) {
                    $q->orWhereNull('locale')->orWhereNotIn('locale', array_keys(Locales::available()));
                }
            }))
            ->when($servers !== [] || $products !== [] || $tlds !== [] || $types !== [], fn (Builder $q) => $q->whereHas('services', fn (Builder $s) => $s
                ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
                ->when($servers !== [], fn (Builder $s) => $s->whereIn('server_id', $servers))
                ->when($products !== [], fn (Builder $s) => $s->whereIn('product_id', $products))
                ->when($types !== [], fn (Builder $s) => $s->whereIn('type', $types))
                ->when($tlds !== [], fn (Builder $s) => $s->where(function (Builder $s) use ($tlds) {
                    foreach ($tlds as $tld) {
                        $s->orWhere('domain', 'like', '%'.$tld);
                    }
                }))));
    }

    /** @return Builder<Client> who the email goes to: matched, with an address, and for an informational one, not opted out */
    public function emailClients(): Builder
    {
        return $this->clients()
            ->whereNotNull('email')->where('email', '!=', '')
            ->when($this->kind === ClientMessageKind::Info, fn (Builder $q) => $q->where('informational_emails', true));
    }

    public function matches(Client $client): bool
    {
        return $this->clients()->whereKey($client->getKey())->exists();
    }

    /** @param  Builder<ClientMessage>  $query  shown on client area Home today */
    public function scopeShowing(Builder $query, ?CarbonInterface $today = null): void
    {
        $today = CarbonImmutable::parse($today ?? today())->toDateString();

        $query->where('show_in_client_area', true)
            ->where(fn (Builder $q) => $q->whereNull('show_from')->orWhereDate('show_from', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('show_until')->orWhereDate('show_until', '>=', $today));
    }

    /** Whether the email went out or is going out: it can no longer be edited or sent again. */
    public function isQueued(): bool
    {
        return $this->queued_at !== null;
    }
}
