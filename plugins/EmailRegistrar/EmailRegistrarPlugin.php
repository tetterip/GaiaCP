<?php

namespace Plugins\EmailRegistrar;

use App\Plugins\Plugin;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ManualRegistrar;
use App\Provisioning\RegistrarModule;

/**
 * The "Email Notifications" registrar (module "email", like WHMCS's): for TLDs that have no integrated registrar.
 * Every registrar action becomes an email to the admins (EmailRegistrar); nothing is booked at any registrar. The
 * account has no login, and the migration that added it creates one.
 */
class EmailRegistrarPlugin extends Plugin implements ManualRegistrar, RegistrarModule
{
    public function id(): string
    {
        return 'email_registrar';
    }

    public function name(): string
    {
        return 'Email Notifications';
    }

    public function description(): string
    {
        return __('A registrar for TLDs with no integrated registrar: registrations, transfers, renewals, nameserver changes and EPP code requests are emailed to the admins, who do them by hand.');
    }

    public function category(): string
    {
        return 'Registrars';
    }

    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email Notifications';
    }

    public function registrar(array $credentials): DomainRegistrar
    {
        return new EmailRegistrar;
    }

    public function credentialFields(): array
    {
        return [];
    }
}
