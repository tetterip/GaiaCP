<?php

namespace App\Http\Requests\Api;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use Closure;
use Illuminate\Validation\Rule;

/**
 * POST /products and PATCH /products/{id}. Prices are in the base currency, per cycle:
 * {"monthly": {"price_cents": 500, "setup_cents": 0}}; a cycle left out is not offered.
 */
class ProductRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->required();
        $cycles = array_map(fn (BillingCycle $cycle) => $cycle->value, BillingCycle::cases());

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$this->isMethod('post') ? 'required' : 'prohibited', Rule::enum(ServiceType::class)],
            'addon' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:10000'],
            'billing_cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
            'prices' => [$required, 'array', 'min:1', function (string $attribute, mixed $value, Closure $fail) use ($cycles) {
                if (is_array($value) && array_diff(array_keys($value), $cycles) !== []) {
                    $fail('Prices are given per billing cycle: '.implode(', ', $cycles).'.');
                }
            }],
            'prices.*' => ['array'],
            'prices.*.price_cents' => ['required', 'integer', 'min:0'],
            'prices.*.setup_cents' => ['nullable', 'integer', 'min:0'],
            'package' => ['nullable', 'string', 'max:255'],
            'server_ids' => ['sometimes', 'array'],
            'server_ids.*' => ['integer', 'exists:servers,id'],
            'hidden' => ['sometimes', 'boolean'],
            'retired' => ['sometimes', 'boolean'],
            'client_visible' => ['sometimes', 'boolean'],
            'auto_setup' => ['sometimes', Rule::enum(AutoSetup::class)],
        ];
    }
}
