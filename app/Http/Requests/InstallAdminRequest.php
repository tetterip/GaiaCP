<?php

namespace App\Http\Requests;

use App\Install\Installer;
use App\Support\Region;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\Intl\Countries;

class InstallAdminRequest extends FormRequest
{
    /** Only reachable while the installer is open (PrepareInstaller). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> A database that already has admins keeps them: no new one is asked for. */
    public function rules(): array
    {
        $admin = app(Installer::class)->hasAdmins() ? ['nullable'] : ['required'];

        return [
            'company' => ['required', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'url' => ['nullable', 'url', 'max:255'],
            'timezone' => ['nullable', Rule::in(DateTimeZone::listIdentifiers())],
            'date_format' => ['nullable', Rule::in(Region::DATE_FORMATS)],
            'country' => ['nullable', Rule::in(Countries::getCountryCodes())],
            'currency' => ['nullable', Rule::in(array_keys(Region::currencyOptions()))],
            'name' => [...$admin, 'string', 'max:255'],
            'email' => [...$admin, 'email', 'max:255', 'unique:users,email'],
            'password' => [...$admin, 'string', 'min:8', 'confirmed'],
        ];
    }
}
