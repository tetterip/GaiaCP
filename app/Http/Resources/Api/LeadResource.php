<?php

namespace App\Http\Resources\Api;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'source' => $this->source,
            'status' => $this->status?->value,
            'lost_reason' => $this->lost_reason,
            'notes' => $this->notes,
            'assigned_to' => $this->assigned_to,
            'next_contact_on' => $this->next_contact_on?->toDateString(),
            'client_id' => $this->client_id,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
