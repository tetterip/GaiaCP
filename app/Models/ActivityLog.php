<?php

namespace App\Models;

use App\Events\GaiaEvent;
use App\Notifications\AdminAlerts;
use App\Notifications\ClientAlerts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'service_id', 'user_id', 'api_key_id', 'event', 'description', 'properties', 'created_at'])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        // The admins' and the clients' bells (each picks the events it shows)
        static::created(function (self $log) {
            AdminAlerts::fromLog($log);
            ClientAlerts::fromLog($log);
            // Plugins and listeners (GaiaEvent picks the events they get)
            GaiaEvent::fromLog($log);
        });
    }

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Record an event. The acting user is the logged-in admin, or null when
     * the scheduler or a console command did it; during a REST API call, its key.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $event, string $description, ?Service $service = null, ?Client $client = null, array $properties = []): self
    {
        return self::create([
            'client_id' => $client?->id ?? $service?->client_id,
            'service_id' => $service?->id,
            'user_id' => auth()->id(),
            'api_key_id' => ApiKey::current()?->id,
            'event' => $event,
            'description' => $description,
            'properties' => $properties ?: null,
        ]);
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

    /** @return BelongsTo<ApiKey, $this> the REST API key of the call that did it */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
