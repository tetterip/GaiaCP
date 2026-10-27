<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The answer on a quote's page (/quote/{token}): accept, with the terms of service when they are asked, or decline with a reason. */
class QuoteAnswerRequest extends FormRequest
{
    /** Whoever has the quote's link may answer it. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accept_terms' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
