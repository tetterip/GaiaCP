<?php

namespace App\Http\Resources\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'addon' => (bool) $this->addon,
            'description' => $this->description,
            'billing_cycle' => $this->billing_cycle?->value,
            'prices' => $this->prices ?: (object) [],
            'currency_prices' => $this->currency_prices ?: (object) [],
            'package' => $this->package,
            'server_ids' => $this->servers->pluck('id')->all(),
            'hidden' => (bool) $this->hidden,
            'retired' => (bool) $this->retired,
            'client_visible' => (bool) $this->client_visible,
            'auto_setup' => $this->auto_setup?->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
