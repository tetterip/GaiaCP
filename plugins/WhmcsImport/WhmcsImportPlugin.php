<?php

namespace Plugins\WhmcsImport;

use App\Plugins\Plugin;

/** Import from a WHMCS database (WhmcsImport): the Settings page and `gaiacp:import-whmcs`. */
class WhmcsImportPlugin extends Plugin
{
    public function id(): string
    {
        return 'whmcs-import';
    }

    public function name(): string
    {
        return 'WHMCS import';
    }

    public function description(): string
    {
        return __('Brings servers, products, clients, services, domains and unpaid invoices over from a WHMCS database.');
    }

    public function category(): string
    {
        return 'Integrations';
    }

    public function settingsPage(): ?string
    {
        return WhmcsImportPage::class;
    }

    public function pages(): array
    {
        return [WhmcsImportPage::class];
    }

    public function commands(): array
    {
        return [ImportWhmcs::class];
    }

    public function moduleLogs(): array
    {
        return ['whmcs' => 'WHMCS'];
    }
}
