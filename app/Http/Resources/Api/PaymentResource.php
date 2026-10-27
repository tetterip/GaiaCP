<?php

namespace App\Http\Resources\Api;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'client_id' => $this->client_id,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'paid_on' => $this->paid_on?->toDateString(),
            'method' => $this->method,
            'reference' => $this->reference,
            'due_date_before' => $this->due_date_before?->toDateString(),
            'due_date_after' => $this->due_date_after?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
