<?php

namespace Plugins\GoDaddy;

use App\Models\Client;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ManagesRegistrarLock;
use App\Provisioning\ProvisioningException;
use App\Support\EppPhone;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * GoDaddy's v1 domain calls: GET /v1/domains/available, GET /v1/domains/agreements (the legal agreements a purchase or
 * transfer must accept), POST /v1/domains/purchase, POST /v1/domains/{domain}/transfer, POST /v1/domains/{domain}/renew,
 * GET /v1/domains/{domain} (expires, nameServers, locked, authCode, contacts), PATCH /v1/domains/{domain}
 * (nameServers, locked) and PATCH /v1/domains/{domain}/contacts. Private nameservers need GoDaddy's v2 customer API,
 * so they are not offered here.
 */
class GoDaddyRegistrar implements ChecksAvailability, DomainRegistrar, ManagesDomainContacts, ManagesRegistrarLock
{
    /** GoDaddy's contact field for each of GaiaCP's roles (DomainContact::ROLES). */
    public const ROLES = ['registrant' => 'contactRegistrant', 'admin' => 'contactAdmin', 'tech' => 'contactTech', 'billing' => 'contactBilling'];

    /** @var array<string, array<string, mixed>> domains read in this run, by name */
    private array $domains = [];

    public function __construct(private GoDaddyClient $client) {}

    public function name(): string
    {
        return 'GoDaddy';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function available(string $domain): bool
    {
        $reply = $this->client->call('GET', 'v1/domains/available', ['domain' => self::normal($domain), 'checkType' => 'FAST', 'forTransfer' => 'false'], timeout: 15);

        if (! array_key_exists('available', $reply)) {
            throw new ProvisioningException("GoDaddy returned no availability for {$domain}.");
        }

        return (bool) $reply['available'];
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $expires = (string) ($this->domain($domain)['expires'] ?? '');

        try {
            // "2027-03-15T23:59:59.000Z": keep its calendar date.
            return CarbonImmutable::createFromFormat('!Y-m-d', substr($expires, 0, 10)) ?: throw new ProvisioningException('');
        } catch (Throwable) {
            throw new ProvisioningException("GoDaddy returned no expiry date for {$domain}.");
        }
    }

    /** Privacy and auto renew off: GaiaCP renews when the client pays. */
    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $contact = $this->contactBody(DomainContact::fromClient($registrant));
        $body = [
            'domain' => self::normal($domain),
            'consent' => $this->consent($domain),
            'period' => $years,
            'privacy' => false,
            'renewAuto' => false,
            ...array_fill_keys(self::ROLES, $contact),
        ];

        if ($nameservers !== []) {
            $body['nameServers'] = array_values($nameservers);
        }

        $this->client->call('POST', 'v1/domains/purchase', $body, timeout: 120);
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        $this->client->call('POST', 'v1/domains/'.self::normal($domain).'/transfer', [
            'authCode' => $eppCode,
            'consent' => $this->consent($domain),
            'period' => 1,
            'privacy' => false,
            'renewAuto' => false,
            'contactRegistrant' => $this->contactBody(DomainContact::fromClient($registrant)),
        ], secrets: [$eppCode], timeout: 120);
    }

    public function renew(string $domain, int $years): void
    {
        $this->client->call('POST', 'v1/domains/'.self::normal($domain).'/renew', ['period' => $years], timeout: 120);
        unset($this->domains[self::normal($domain)]);
    }

    public function nameservers(string $domain): array
    {
        return array_values(array_filter(array_map(fn ($ns) => strtolower(trim((string) $ns)), (array) ($this->domain($domain)['nameServers'] ?? []))));
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        if ($nameservers === []) {
            throw new ProvisioningException('GoDaddy needs at least two nameservers.');
        }

        $this->update($domain, ['nameServers' => array_values($nameservers)]);
    }

    public function eppCode(string $domain): string
    {
        $code = $this->domain($domain)['authCode'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new ProvisioningException("GoDaddy returned no EPP code for {$domain}.");
        }

        return $code;
    }

    public function contactRoles(): array
    {
        return array_keys(self::ROLES);
    }

    public function contacts(string $domain): array
    {
        $record = $this->domain($domain);
        $contacts = [];

        foreach (self::ROLES as $role => $field) {
            if (is_array($record[$field] ?? null)) {
                $contacts[$role] = self::contact($record[$field]);
            }
        }

        return $contacts;
    }

    public function saveContacts(string $domain, array $contacts): void
    {
        $body = [];

        foreach (self::ROLES as $role => $field) {
            if (isset($contacts[$role])) {
                $body[$field] = $this->contactBody($contacts[$role]);
            }
        }

        if ($body !== []) {
            $this->client->call('PATCH', 'v1/domains/'.self::normal($domain).'/contacts', $body);
            unset($this->domains[self::normal($domain)]);
        }
    }

    public function locked(string $domain): bool
    {
        return (bool) ($this->domain($domain)['locked'] ?? false);
    }

    public function setLocked(string $domain, bool $locked): void
    {
        $this->update($domain, ['locked' => $locked]);
    }

    /** @return array<string, mixed> */
    private function domain(string $domain): array
    {
        $name = self::normal($domain);

        return $this->domains[$name] ??= $this->client->call('GET', 'v1/domains/'.$name);
    }

    /** @param  array<string, mixed>  $changes */
    private function update(string $domain, array $changes): void
    {
        $this->client->call('PATCH', 'v1/domains/'.self::normal($domain), $changes);
        unset($this->domains[self::normal($domain)]);
    }

    /**
     * The agreements GoDaddy asks for this TLD, accepted now by the admin's server, as its consent block wants.
     *
     * @return array{agreementKeys: list<string>, agreedBy: string, agreedAt: string}
     */
    private function consent(string $domain): array
    {
        $tld = substr(self::normal($domain), strpos(self::normal($domain), '.') + 1);
        $keys = array_values(array_filter(array_map(
            fn ($agreement) => is_array($agreement) ? ($agreement['agreementKey'] ?? null) : null,
            $this->client->call('GET', 'v1/domains/agreements', ['tlds' => $tld, 'privacy' => 'false']),
        )));

        return [
            'agreementKeys' => $keys,
            'agreedBy' => (string) (request()->ip() ?? '127.0.0.1'),
            'agreedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array<string, mixed> */
    private function contactBody(DomainContact $contact): array
    {
        return array_filter([
            'nameFirst' => $contact->first_name,
            'nameLast' => $contact->last_name,
            'organization' => $contact->company,
            'email' => $contact->email,
            'phone' => EppPhone::format($contact->phone, $contact->country),
            'addressMailing' => array_filter([
                'address1' => $contact->address1,
                'address2' => $contact->address2,
                'city' => $contact->city,
                'state' => $contact->state,
                'postalCode' => $contact->postcode,
                'country' => $contact->country,
            ], fn ($value) => $value !== ''),
        ], fn ($value) => $value !== '' && $value !== []);
    }

    /** @param  array<string, mixed>  $contact */
    private static function contact(array $contact): DomainContact
    {
        $address = is_array($contact['addressMailing'] ?? null) ? $contact['addressMailing'] : [];

        return DomainContact::fromArray([
            'first_name' => $contact['nameFirst'] ?? '',
            'last_name' => $contact['nameLast'] ?? '',
            'company' => $contact['organization'] ?? '',
            'address1' => $address['address1'] ?? '',
            'address2' => $address['address2'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'postcode' => $address['postalCode'] ?? '',
            'country' => $address['country'] ?? '',
            'phone' => $contact['phone'] ?? '',
            'email' => $contact['email'] ?? '',
        ]);
    }

    private static function normal(string $domain): string
    {
        return strtolower(rtrim(trim($domain), '.'));
    }
}
