<?php

namespace App\Http\Requests\Api;

/** POST /services/{id}/suspend */
class SuspendServiceRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
