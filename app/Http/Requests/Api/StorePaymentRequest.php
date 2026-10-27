<?php

namespace App\Http\Requests\Api;

use App\Payments\PaymentMethods;
use Illuminate\Validation\Rule;

/** POST /payments (one cycle of a service) and POST /invoices/{id}/pay (every unpaid line of a notice). */
class StorePaymentRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $forService = $this->route('invoice') === null;

        return [
            'service_id' => $forService ? ['required', 'integer', 'exists:services,id'] : ['prohibited'],
            'amount_cents' => $forService ? ['sometimes', 'integer', 'min:0'] : ['prohibited'],
            'paid_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['sometimes', Rule::in(array_keys(PaymentMethods::options()))],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => $forService ? ['nullable', 'string', 'max:5000'] : ['prohibited'],
        ];
    }
}
