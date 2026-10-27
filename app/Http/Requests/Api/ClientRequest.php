<?php

namespace App\Http\Requests\Api;

use App\Enums\ClientStatus;
use App\Payments\PaymentMethods;
use App\Support\Currencies;
use App\Support\Locales;
use Illuminate\Validation\Rule;
use Symfony\Component\Intl\Countries;

/** POST /clients and PATCH /clients/{id}: the same fields as the client form. */
class ClientRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->required();

        return [
            'first_name' => [$required, 'string', 'max:255'],
            'last_name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'string', Rule::in(array_keys(Countries::getNames('en')))],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'tax_office' => ['nullable', 'string', 'max:255'],
            'profession' => ['nullable', 'string', 'max:255'],
            'tax_exempt' => ['sometimes', 'boolean'],
            'locale' => ['nullable', Rule::in(array_keys(Locales::available()))],
            'currency' => ['sometimes', Rule::in(array_keys(Currencies::options()))],
            'status' => ['sometimes', Rule::enum(ClientStatus::class)],
            'default_payment_method' => ['nullable', Rule::in(array_keys(PaymentMethods::options()))],
            'renewal_reminders' => ['sometimes', 'boolean'],
            'overdue_notices' => ['sometimes', 'boolean'],
            'informational_emails' => ['sometimes', 'boolean'],
            'custom_fields' => ['sometimes', 'nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
