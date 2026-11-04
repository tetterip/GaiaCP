<?php

namespace App\Provisioning;

/**
 * A DomainRegistrar that tells what it charges for each TLD: Settings → Domain pricing → Import from registrar reads
 * it, and the daily cost sync (Settings → Domains) keeps `tlds.costs` up to date with it.
 */
interface ListsTldPrices
{
    /**
     * Whether tldPrices() can list every TLD the registrar sells. When not, it reads only the TLDs it is given.
     */
    public function listsAllTlds(): bool;

    /**
     * What the registrar charges, one entry per TLD it sells.
     *
     * @param  list<string>|null  $tlds  only these (".com"); null = every TLD, when listsAllTlds()
     * @return list<TldPrice>
     *
     * @throws ProvisioningException
     */
    public function tldPrices(?array $tlds = null): array;
}
