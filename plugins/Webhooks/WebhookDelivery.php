<?php

namespace Plugins\Webhooks;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event for one endpoint: the JSON sent (the same on every try) and how the last try went. */
#[Fillable(['uuid', 'webhook_endpoint_id', 'event', 'payload', 'status', 'attempts', 'next_attempt_at', 'response_code', 'response_body', 'error', 'duration_ms', 'delivered_at'])]
class WebhookDelivery extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
