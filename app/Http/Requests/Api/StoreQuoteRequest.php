<?php

namespace App\Http\Requests\Api;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /quotes: for a lead or a client, items as the panel's New order makes them (prices in cents, set here and kept
 * on renewals) and one-off lines. It is saved as a draft: POST /quotes/{id}/send emails it.
 */
class StoreQuoteRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lead_id' => ['required_without:client_id', 'nullable', 'integer', Rule::exists('leads', 'id')],
            'client_id' => ['required_without:lead_id', 'nullable', 'integer', Rule::exists('clients', 'id')],
            'items' => ['required_without:extras', 'array', 'max:50'],
            'items.*.type' => ['required', Rule::enum(ServiceType::class)],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'items.*.label' => ['nullable', 'string', 'max:255'],
            'items.*.domain' => ['nullable', 'string', 'max:255'],
            'items.*.registration_type' => ['nullable', Rule::enum(DomainRegistrationType::class)],
            'items.*.registrar_id' => ['nullable', 'integer', Rule::exists('registrars', 'id')],
            'items.*.server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')],
            'items.*.package' => ['nullable', 'string', 'max:255'],
            'items.*.billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'items.*.price_cents' => ['required', 'integer', 'min:0'],
            'items.*.first_payment_cents' => ['nullable', 'integer', 'min:0'],
            'items.*.options' => ['nullable', 'array'],
            'items.*.nameservers' => ['nullable', 'array'],
            'items.*.auto_renew' => ['sometimes', 'boolean'],
            'extras' => ['required_without:items', 'array', 'max:50'],
            'extras.*.description' => ['required', 'string', 'max:255'],
            'extras.*.amount_cents' => ['required', 'integer', 'min:0'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'intro' => ['nullable', 'string', 'max:5000'],
            'footer' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ($this->input('items', []) as $index => $item) {
                $type = ServiceType::tryFrom((string) ($item['type'] ?? ''));
                $cycle = (string) ($item['billing_cycle'] ?? '');

                if ($type && $cycle !== '' && ! array_key_exists($cycle, BillingCycle::optionsFor($type))) {
                    $validator->errors()->add("items.{$index}.billing_cycle", 'One time is only for other services.');
                }

                if ($type === ServiceType::Domain ? blank($item['domain'] ?? null) : blank($item['label'] ?? null)) {
                    $validator->errors()->add("items.{$index}", $type === ServiceType::Domain ? 'A domain needs its domain.' : 'An item needs its label.');
                }
            }
        }];
    }
}
