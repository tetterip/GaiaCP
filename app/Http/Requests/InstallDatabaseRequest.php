<?php

namespace App\Http\Requests;

use App\Install\Installer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InstallDatabaseRequest extends FormRequest
{
    /** Only reachable while the installer is open (PrepareInstaller). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'driver' => ['required', Rule::in(array_keys(app(Installer::class)->availableDrivers()))],
            'host' => ['nullable', 'required_if:driver,mysql', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['nullable', 'required_if:driver,mysql', 'string', 'max:255'],
            'username' => ['nullable', 'required_if:driver,mysql', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }
}
