<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** A REST API call's input. The key and its permission are checked by the route (AuthenticateApiKey). */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** PATCH changes only the fields sent; POST needs the required ones. */
    protected function required(): string
    {
        return $this->isMethod('post') ? 'required' : 'sometimes';
    }
}
