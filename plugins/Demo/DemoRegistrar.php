<?php

namespace Plugins\Demo;

use App\Enums\ServiceType;
use App\Models\Client;
use App\Models\Service;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ProvisioningException;
use Carbon\CarbonImmutable;

/**
 * The demo's registrar: a domain is "registered" when GaiaCP has it, expiring on its stored expiry (else its next due
 * date). Registering, transferring and renewing always work and only move that expiry; nameservers are GaiaCP's own.
 * A search finds a name taken when GaiaCP has it or it is a famous one.
 */
class DemoRegistrar implements ChecksAvailability, DomainRegistrar
{
    public const NAMESERVERS = ['ns1.demo-hosting.example', 'ns2.demo-hosting.example'];

    private const FAMOUS = ['google', 'facebook', 'amazon', 'wikipedia', 'youtube', 'example', 'microsoft', 'apple'];

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

        return ! in_array($name, self::FAMOUS, true) && ! $this->service($domain);
    }

    private function service(string $domain): ?Service
    {
        return Service::query()->where('type', ServiceType::Domain)->where('domain', strtolower($domain))->latest('id')->first();
    }
}
