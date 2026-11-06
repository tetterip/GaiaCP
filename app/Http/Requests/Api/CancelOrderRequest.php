<?php

namespace App\Http\Requests\Api;

use App\Actions\CancelOrder;
use Illuminate\Validation\Rule;

/** POST /orders/{id}/cancel */
class CancelOrderRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payments' => ['sometimes', 'nullable', Rule::in(CancelOrder::PAYMENT_OPTIONS)],
        ];
    }
}
