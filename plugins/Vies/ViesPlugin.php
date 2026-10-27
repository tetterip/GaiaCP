<?php

namespace Plugins\Vies;

use App\Models\Client;
use App\Plugins\Plugin;

/** EU VAT number checks in VIES on the client form, and the intra-EU reverse charge on payment notices. */
class ViesPlugin extends Plugin
{
    public function id(): string
    {
        return 'vies';
    }

    public function name(): string
    {
        return 'VIES VAT check';
    }

    public function description(): string
    {
        return __('Checks a client\'s EU VAT number in VIES, and can leave VAT off for EU businesses in another EU country (reverse charge).');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    /** Off in a new install: only sellers in the EU use it. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function settingsPage(): ?string
    {
        return ViesSettings::class;
    }

    public function pages(): array
    {
        return [ViesSettings::class];
    }

    public function settingKeys(): array
    {
        return ['vies.home_country', 'vies.requester_vat', 'vies.reverse_charge', 'vies.valid_days', 'vies.reverse_charge_note'];
    }

    public function moduleLogs(): array
    {
        return ['vies' => 'VIES'];
    }

    public function clientVatNumberActions(): array
    {
        return [CheckAction::make()];
    }

    public function taxExemption(Client $client): ?string
    {
        return Vies::exemption($client);
    }
}
