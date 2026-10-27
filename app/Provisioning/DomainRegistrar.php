<?php

namespace App\Provisioning;

use App\Models\Client;
use Carbon\CarbonImmutable;

/**
 * The one registrar GaiaCP uses. Methods throw ProvisioningException on
 * failure. Callers (App\Actions\DomainLifecycle) handle state checks, status
 * changes and logging; implementations only talk to the registrar.
 */
interface DomainRegistrar
{
    /** Display name, e.g. "easy.gr". */
    public function name(): string;

    /** Whether credentials are set; calls throw when they are not. */
    public function configured(): bool;

    /** Expiry date of a domain in the registrar account; throws if the domain is not there. */
    public function expiryDate(string $domain): CarbonImmutable;

    /** @param  list<string>  $nameservers */
    public function register(string $domain, int $years, Client $registrant, array $nameservers): void;

    public function transfer(string $domain, string $eppCode, Client $registrant): void;

    public function renew(string $domain, int $years): void;

    /** @return list<string> */
    public function nameservers(string $domain): array;

    /** @param  list<string>  $nameservers */
    public function setNameservers(string $domain, array $nameservers): void;

    public function eppCode(string $domain): string;
}
