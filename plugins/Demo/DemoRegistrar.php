<?php

namespace Plugins\Demo;

use App\Enums\ServiceType;
use App\Models\Client;
use App\Models\Service;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ListsTldPrices;
use App\Provisioning\ProvisioningException;
use App\Provisioning\TldPrice;
use App\Support\Demo;
use Carbon\CarbonImmutable;

/**
 * The demo's registrar: a domain is "registered" when GaiaCP has it, expiring on its stored expiry (else its next due
 * date). Registering, transferring and renewing always work and only move that expiry; nameservers are GaiaCP's own.
 * A search finds a name taken when GaiaCP has it or it is a famous one. Its made-up costs (COSTS) feed Domain pricing →
 * Import from registrar and the daily cost sync.
 */
class DemoRegistrar implements ChecksAvailability, DomainRegistrar, ListsTldPrices
{
    public const NAMESERVERS = ['ns1.demo-hosting.example', 'ns2.demo-hosting.example'];

    /** TLD => [register, renew, transfer] for a year, in EUR cents (null: not sold); the first six are the demo's TLDs (DemoSeeder). */
    public const COSTS = [
        '.com' => [900, 900, 900], '.gr' => [1000, 1000, null], '.eu' => [600, 600, 600], '.net' => [1050, 1050, 1050],
        '.org' => [1000, 1000, 1000], '.io' => [3600, 3600, 3600], '.de' => [650, 650, 650], '.dev' => [1100, 1100, 1100],
        '.app' => [1300, 1300, 1300], '.cloud' => [1600, 1600, 1600], '.shop' => [300, 2900, 2900], '.online' => [150, 2800, 2800],
    ];

    public function listsAllTlds(): bool
    {
        return true;
    }

    public function tldPrices(?array $tlds = null): array
    {
        $wanted = $tlds === null ? null : array_map(fn (string $tld) => '.'.ltrim(mb_strtolower(trim($tld)), '.'), $tlds);

        return collect(self::COSTS)
            ->when($wanted !== null, fn ($costs) => $costs->only($wanted))
            ->map(fn (array $cents, string $tld) => new TldPrice($tld, 1, 'EUR', $cents[0], $cents[1], $cents[2]))
            ->values()
            ->all();
    }

    public function name(): string
    {
        return __('Demo registrar');
    }

    public function configured(): bool
    {
        return true;
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $service = $this->service($domain);

        if (! $service) {
            throw new ProvisioningException("{$domain} is not in the demo registrar account.");
        }

        return $service->domainDetails?->expires_at ?? CarbonImmutable::parse($service->next_due_date);
    }

    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $this->service($domain)?->registration()->update(['expires_at' => today()->addYears($years)]);
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        $this->service($domain)?->registration()->update(['expires_at' => today()->addYear()]);
    }

    public function renew(string $domain, int $years): void
    {
        $service = $this->service($domain);

        if ($service) {
            $service->registration()->update(['expires_at' => $this->expiryDate($domain)->addYears($years)]);
        }
    }

    public function nameservers(string $domain): array
    {
        return $this->service($domain)?->domainDetails?->nameservers ?: self::NAMESERVERS;
    }

    public function setNameservers(string $domain, array $nameservers): void {}

    public function eppCode(string $domain): string
    {
        return 'DEMO-'.strtoupper(substr(hash('sha256', strtolower($domain)), 0, 10));
    }

    public function available(string $domain): bool
    {
        $name = strtolower(explode('.', $domain)[0]);

        return ! in_array($name, Demo::FAMOUS, true) && ! $this->service($domain);
    }

    private function service(string $domain): ?Service
    {
        return Service::query()->where('type', ServiceType::Domain)->where('domain', strtolower($domain))->latest('id')->first();
    }
}
