<?php

namespace App\Http\Requests\Api;

use App\Enums\LeadStatus;
use Illuminate\Validation\Rule;
use Symfony\Component\Intl\Countries;

/** POST /leads (e.g. a contact form on the website) and PATCH /leads/{id}: the fields of the lead form. */
class LeadRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => [$this->required(), 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', Rule::in(array_keys(Countries::getNames('en')))],
            'source' => ['nullable', 'string', 'max:255'],
            // Won and Lost come from converting and losing it in the panel.
            'status' => ['sometimes', Rule::in(array_map(fn (LeadStatus $status) => $status->value, LeadStatus::open()))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'next_contact_on' => ['nullable', 'date'],
        ];
    }
}
