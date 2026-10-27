<?php

namespace Plugins\Webhooks;

use App\Events\GaiaEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A URL that gets the events it chose as signed JSON POSTs (Settings → Webhooks). */
#[Fillable(['name', 'url', 'secret', 'events', 'active', 'failures', 'alerted_at', 'last_success_at', 'last_failure_at'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    protected $attributes = [
        'active' => true,
        'failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'failures' => 'integer',
            'alerted_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
        ];
    }

    /** A new signing secret, shown to the admin once. */
    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function wants(string $event): bool
    {
        $events = $this->events ?? [];

        return in_array('*', $events, true) || in_array($event, $events, true);
    }

    /** The events it gets, for the list: "All events" or their labels. */
    public function eventsLabel(): string
    {
        $events = $this->events ?? [];

        if (in_array('*', $events, true)) {
            return __('All events');
        }

        $labels = GaiaEvent::options();

        return collect($events)->map(fn (string $event) => $labels[$event] ?? $event)->join(', ');
    }
}
