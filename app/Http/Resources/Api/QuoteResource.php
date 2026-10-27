<?php

namespace App\Http\Resources\Api;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $totals = $this->totals();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status?->value,
            'lead_id' => $this->lead_id,
            'client_id' => $this->client_id,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'items' => $this->items ?? [],
            'extras' => $this->extras ?? [],
            'lines' => $this->lines(),
            'net_cents' => $totals['net'],
            'tax_cents' => $totals['tax'],
            'total_cents' => $totals['total'],
            'intro' => $this->intro,
            'footer' => $this->footer,
            'valid_until' => $this->valid_until?->toDateString(),
            'url' => $this->url(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'declined_at' => $this->declined_at?->toIso8601String(),
            'decline_reason' => $this->decline_reason,
            'order_id' => $this->order_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
