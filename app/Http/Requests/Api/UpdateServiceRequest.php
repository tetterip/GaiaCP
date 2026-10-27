<?php

namespace App\Http\Requests\Api;

/** PATCH /services/{id}: what can change on a service without the panel (Suspend, Terminate… have their own calls). */
class UpdateServiceRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'client_visible' => ['sometimes', 'boolean'],
            'override_suspend_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'auto_terminate' => ['sometimes', 'boolean'],
        ];
    }
}
