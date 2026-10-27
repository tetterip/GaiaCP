<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ServiceType: string implements HasLabel
{
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Maintenance = 'maintenance';
    case Ssl = 'ssl';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Hosting => 'Hosting',
            self::Domain => 'Domain',
            self::Maintenance => 'Maintenance',
            self::Ssl => 'SSL certificate',
            self::Other => 'Other service',
        });
    }

    /** Only hosting lives on a server and is provisioned through a panel API. */
    public function needsServer(): bool
    {
        return $this === self::Hosting;
    }

    /** Whether it is for a domain name, entered when it is ordered (hosting, an SSL certificate). */
    public function needsDomain(): bool
    {
        return in_array($this, [self::Domain, self::Hosting, self::Ssl], true);
    }

    /** Whether clients may order it in the client area. Maintenance is an add-on only the admin sets up: clients see it, read-only. */
    public function clientOrderable(): bool
    {
        return $this !== self::Maintenance;
    }
}
