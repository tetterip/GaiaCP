<?php

namespace App\Http\Resources\Api;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
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
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'vat_number' => $this->vat_number,
            'tax_office' => $this->tax_office,
            'profession' => $this->profession,
            'tax_exempt' => $this->tax_exempt,
            'locale' => $this->locale,
            'currency' => $this->currencyCode(),
            'status' => $this->status?->value,
            'default_payment_method' => $this->default_payment_method,
            'renewal_reminders' => $this->renewal_reminders,
            'overdue_notices' => $this->overdue_notices,
            'informational_emails' => $this->informational_emails,
            'credit_cents' => (int) $this->credit_cents,
            'custom_fields' => $this->custom_fields ?: (object) [],
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
