<?php

namespace App\Http\Requests\Api;

/** POST /quotes/{id}/decline: why, when the client said. */
class DeclineQuoteRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
