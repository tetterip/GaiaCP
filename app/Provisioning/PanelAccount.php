<?php

namespace App\Provisioning;

/** A hosting account as the panel lists it (ListsAccounts::accounts()). */
final readonly class PanelAccount
{
    public function __construct(
        public string $username,
        public ?string $domain,
        public ?string $package,
        public bool $suspended,
    ) {}
}
