<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a pending domain becomes active: registered, transferred in, or only tracked.
 * A tracked domain is registered elsewhere: GaiaCP never calls a registrar for it
 * (no renewal on payment, no sync).
 */
enum DomainRegistrationType: string implements HasLabel
{
    case Register = 'register';
    case Transfer = 'transfer';
    case External = 'external';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Register => 'Register',
            self::Transfer => 'Transfer in',
            self::External => 'Registered elsewhere (track only)',
        });
    }
}
