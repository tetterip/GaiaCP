<?php

namespace Plugins\Aade;

use App\Plugins\Plugin;

/** ΑΑΔΕ registry lookup by ΑΦΜ on the client form (AadeRegistry), with its settings page (AadeSettings). */
class AadePlugin extends Plugin
{
    public function id(): string
    {
        return 'aade';
    }

    public function name(): string
    {
        return 'ΑΑΔΕ';
    }

    public function description(): string
    {
        return __('Fills in a client\'s name, Δ.Ο.Υ., profession and address from their ΑΦΜ.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    /** Off in a new install: only Greek sellers use it. */
    public function enabledByDefault(): bool
    {
        return false;
    }

    public function settingsPage(): ?string
    {
        return AadeSettings::class;
    }

    public function pages(): array
    {
        return [AadeSettings::class];
    }

    public function settingKeys(): array
    {
        return ['aade.enabled', 'aade.username', 'aade.password', 'aade.afm_called_by'];
    }

    public function encryptedSettingKeys(): array
    {
        return ['aade.password'];
    }

    public function moduleLogs(): array
    {
        return ['aade' => 'ΑΑΔΕ'];
    }

    public function clientVatNumberActions(): array
    {
        return [LookupAction::make()];
    }
}
