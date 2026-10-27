<?php

namespace App\Http\Resources\Api;

use App\Enums\ServiceType;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    /** @return array<string, mixed> no password, EPP code or panel login */
    public function toArray(Request $request): array
    {
        $domain = $this->type === ServiceType::Domain ? $this->domainDetails : null;

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'order_id' => $this->order_id,
            'parent_service_id' => $this->parent_service_id,
            'type' => $this->type?->value,
            'product_id' => $this->product_id,
            'server_id' => $this->server_id,
            'label' => $this->label,
            'domain' => $this->domain,
            'username' => $this->username,
            'package' => $this->package,
            'options' => $this->options ?: [],
            'status' => $this->status?->value,
            'suspension_reason' => $this->suspension_reason,
            'price_cents' => $this->price_cents,
            'first_payment_cents' => $this->first_payment_cents,
            'new_price_cents' => $this->new_price_cents,
            'new_price_from' => $this->new_price_from?->toDateString(),
            'currency' => $this->currency,
            'billing_cycle' => $this->billing_cycle?->value,
            'start_date' => $this->start_date?->toDateString(),
            'next_due_date' => $this->next_due_date?->toDateString(),
            'auto_terminate' => (bool) $this->auto_terminate,
            'override_suspend_until' => $this->override_suspend_until?->toDateString(),
            'client_visible' => (bool) $this->client_visible,
            'notes' => $this->notes,
            'registration' => $domain ? [
                'registrar_id' => $domain->registrar_id,
                'registration_type' => $domain->registration_type?->value,
                'nameservers' => $domain->nameservers ?? [],
                'registered_at' => $domain->registered_at?->toDateString(),
                'expires_at' => $domain->expires_at?->toDateString(),
                'last_synced_at' => $domain->last_synced_at?->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
