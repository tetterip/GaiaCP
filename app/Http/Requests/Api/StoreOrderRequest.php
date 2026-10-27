<?php

namespace App\Http\Requests\Api;

use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use Illuminate\Validation\Rule;

/**
 * POST /orders: like the client area's cart, one entry per product (with its domain and add-ons) or per domain on
 * its own. Prices always come from the catalogue.
 */
class StoreOrderRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'items' => ['required', 'array', 'min:1', 'max:'.ClientOrder::CART_LIMIT],
            'items.*.product_id' => ['required_without:items.*.domain', 'nullable', 'integer'],
            'items.*.billing_cycle' => ['nullable', Rule::enum(BillingCycle::class)],
            'items.*.options' => ['nullable', 'array'],
            'items.*.addons' => ['nullable', 'array'],
            'items.*.addons.*' => ['integer'],
            'items.*.domain' => ['nullable', 'string', 'max:253'],
            'items.*.domain_option' => ['nullable', Rule::in([ClientOrder::OWN, ClientOrder::REGISTER, ClientOrder::TRANSFER])],
            'items.*.years' => ['nullable', 'integer', 'min:1', 'max:10'],
            'items.*.epp_code' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
