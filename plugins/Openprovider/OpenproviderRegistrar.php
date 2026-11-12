<?php

namespace Plugins\Openprovider;

use App\Models\Client;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ListsTldPrices;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ManagesRegistrarLock;
use App\Provisioning\ProvisioningException;
use App\Provisioning\TldPrice;
use App\Support\EppPhone;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Openprovider's REST calls: POST /domains/check, POST /domains, POST /domains/transfer, POST /domains/{id}/renew,
 * GET /domains (found by name and extension), PUT /domains/{id} (nameservers, lock, contact handles),
 * GET /domains/{id}/authcode, POST / GET /customers (contacts are customer handles a domain points to),
 * POST / PUT / DELETE /dns/nameservers, and GET /tlds/{tld}?with_price=true for the costs.
 */
class OpenproviderRegistrar implements ChecksAvailability, DomainRegistrar, ListsTldPrices, ManagesChildNameservers, ManagesDomainContacts, ManagesRegistrarLock
{
    /** The handle field of each of GaiaCP's roles (DomainContact::ROLES). */
    public const ROLES = ['registrant' => 'owner_handle', 'admin' => 'admin_handle', 'tech' => 'tech_handle', 'billing' => 'billing_handle'];

    /** Openprovider's own nameservers, used when a domain is registered without any. */
    public const DEFAULT_NS_GROUP = 'dns-openprovider';

    /** TLDs read in one go: one call each. */
    public const MAX_PRICED_TLDS = 50;

    /** @var array<string, array<string, mixed>> domains read in this run, by name */
    private array $domains = [];

    public function __construct(private OpenproviderClient $client) {}

    public function name(): string
    {
        return 'Openprovider';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /** A premium name costs more than the TLD's price: GaiaCP can't sell it at that price, so it counts as taken. */
    public function available(string $domain): bool
    {
        $result = $this->client->call('POST', 'domains/check', ['domains' => [self::split($domain)], 'with_price' => false], timeout: 15)['results'][0] ?? null;

        if (! is_array($result) || ! isset($result['status'])) {
            throw new ProvisioningException("Openprovider returned no availability for {$domain}.");
        }

        return $result['status'] === 'free' && ! ($result['is_premium'] ?? false);
    }

    /** Openprovider is asked about the TLDs given, one call each. */
    public function listsAllTlds(): bool
    {
        return false;
    }

    public function tldPrices(?array $tlds = null): array
    {
        $tlds = array_values(array_unique(array_map(fn (string $tld) => ltrim(mb_strtolower(trim($tld)), '.'), $tlds ?? [])));

        if ($tlds === []) {
            throw new ProvisioningException('Name the TLDs to read from Openprovider.');
        }

        if (count($tlds) > self::MAX_PRICED_TLDS) {
            throw new ProvisioningException('Openprovider is asked about at most '.self::MAX_PRICED_TLDS.' TLDs at a time.');
        }

        $list = [];
        $failed = null;

        foreach ($tlds as $tld) {
            try {
                $prices = $this->client->call('GET', 'tlds/'.rawurlencode($tld), ['with_price' => 'true'], timeout: 30)['prices'] ?? [];
            } catch (ProvisioningException $e) {
                // A TLD Openprovider does not sell; the error stands only when no TLD could be read.
                $failed = $e;

                continue;
            }

            $price = fn (string $kind) => TldPrice::cents($prices[$kind]['reseller']['price'] ?? $prices[$kind]['product']['price'] ?? null);
            $currency = strtoupper((string) ($prices['create_price']['reseller']['currency'] ?? $prices['create_price']['product']['currency'] ?? 'EUR'));

            if ($price('create_price') === null && $price('renew_price') === null && $price('transfer_price') === null) {
                continue;
            }

            $list[] = new TldPrice(
                tld: '.'.$tld,
                years: 1,
                currency: $currency,
                registerCents: $price('create_price'),
                renewCents: $price('renew_price'),
                transferCents: $price('transfer_price'),
                redemptionCents: $price('restore_price'),
            );
        }

        if ($list === [] && $failed !== null) {
            throw $failed;
        }

        return $list;
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $expires = (string) ($this->domain($domain)['expiration_date'] ?? '');

        try {
            // "2027-03-15 10:00:00": keep its calendar date.
            return CarbonImmutable::createFromFormat('!Y-m-d', substr($expires, 0, 10)) ?: throw new ProvisioningException('');
        } catch (Throwable) {
            throw new ProvisioningException("Openprovider returned no expiry date for {$domain}.");
        }
    }

    /** Auto renew off: GaiaCP renews when the client pays. */
    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $handle = $this->createCustomer(DomainContact::fromClient($registrant));

        $this->client->call('POST', 'domains', [
            'domain' => self::split($domain),
            'period' => $years,
            'autorenew' => 'off',
            ...array_fill_keys(self::ROLES, $handle),
            ...$this->nameserverBody($nameservers),
        ], timeout: 120);
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        $handle = $this->createCustomer(DomainContact::fromClient($registrant));

        $this->client->call('POST', 'domains/transfer', [
            'domain' => self::split($domain),
            'auth_code' => $eppCode,
            'period' => 1,
            'autorenew' => 'off',
            ...array_fill_keys(self::ROLES, $handle),
        ], secrets: [$eppCode], timeout: 120);
    }

    public function renew(string $domain, int $years): void
    {
        $this->client->call('POST', 'domains/'.$this->id($domain).'/renew', ['domain' => self::split($domain), 'period' => $years], timeout: 120);
        unset($this->domains[self::normal($domain)]);
    }

    public function nameservers(string $domain): array
    {
        $nameservers = $this->domain($domain)['name_servers'] ?? [];
        usort($nameservers, fn (array $a, array $b) => (int) ($a['seq_nr'] ?? 0) <=> (int) ($b['seq_nr'] ?? 0));

        return array_values(array_filter(array_map(fn (array $ns) => strtolower(trim((string) ($ns['name'] ?? ''))), $nameservers)));
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $this->update($domain, $this->nameserverBody($nameservers));
    }

    public function eppCode(string $domain): string
    {
        $code = $this->client->call('GET', 'domains/'.$this->id($domain).'/authcode')['auth_code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new ProvisioningException("Openprovider returned no EPP code for {$domain}.");
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
        $read = [];
        $contacts = [];

        foreach (self::ROLES as $role => $field) {
            $handle = (string) ($record[$field] ?? '');

            if ($handle === '') {
                continue;
            }

            $contacts[$role] = $read[$handle] ??= self::contact($this->client->call('GET', 'customers/'.rawurlencode($handle)));
        }

        return $contacts;
    }

    /**
     * A handle may serve other domains too, so each new contact becomes a new customer handle (one per distinct set
     * of details), and the domain points its roles at them.
     */
    public function saveContacts(string $domain, array $contacts): void
    {
        $handles = [];
        $made = [];

        foreach (self::ROLES as $role => $field) {
            if (isset($contacts[$role])) {
                $key = serialize($contacts[$role]->toArray());
                $handles[$field] = $made[$key] ??= $this->createCustomer($contacts[$role]);
            }
        }

        if ($handles !== []) {
            $this->update($domain, $handles);
        }
    }

    public function saveChildNameserver(string $domain, string $host, string $ip, ?string $oldIp = null): void
    {
        $oldIp !== null
            ? $this->client->call('PUT', 'dns/nameservers/'.rawurlencode(self::normal($host)), ['name' => self::normal($host), 'ip' => $ip])
            : $this->client->call('POST', 'dns/nameservers', ['name' => self::normal($host), 'ip' => $ip]);
    }

    public function deleteChildNameserver(string $domain, string $host): void
    {
        $this->client->call('DELETE', 'dns/nameservers/'.rawurlencode(self::normal($host)));
    }

    public function locked(string $domain): bool
    {
        return (bool) ($this->domain($domain)['is_locked'] ?? false);
    }

    public function setLocked(string $domain, bool $locked): void
    {
        $this->update($domain, ['is_locked' => $locked]);
    }

    /** @return array{name: string, extension: string} "acme.co.uk" → acme, co.uk */
    public static function split(string $domain): array
    {
        $domain = self::normal($domain);

        if (! str_contains($domain, '.')) {
            throw new ProvisioningException("{$domain} is not a domain name.");
        }

        [$name, $extension] = explode('.', $domain, 2);

        return ['name' => $name, 'extension' => $extension];
    }

    /**
     * Street and house number apart, as Openprovider keeps them: "Ερμού 12" → Ερμού, 12; "1 Main St" → Main St, 1.
     *
     * @return array{0: string, 1: string}
     */
    public static function streetAndNumber(string $address): array
    {
        $address = trim($address);

        if (preg_match('/^(.+?)[\s,]+(\d+[\p{L}\d\/\-]*)$/u', $address, $m)) {
            return [trim($m[1]), $m[2]];
        }

        if (preg_match('/^(\d+[\p{L}\d\/\-]*)[\s,]+(.+)$/u', $address, $m)) {
            return [trim($m[2]), $m[1]];
        }

        return [$address, ''];
    }

    /** @return array<string, mixed> the domain's record; throws when the account does not hold it */
    private function domain(string $domain): array
    {
        $name = self::normal($domain);

        if (isset($this->domains[$name])) {
            return $this->domains[$name];
        }

        ['name' => $sld, 'extension' => $extension] = self::split($name);
        $results = $this->client->call('GET', 'domains', ['domain_name_pattern' => $sld, 'extension' => $extension, 'limit' => 10])['results'] ?? [];

        foreach ($results as $record) {
            if (is_array($record) && strtolower(($record['domain']['name'] ?? '').'.'.($record['domain']['extension'] ?? '')) === $name) {
                return $this->domains[$name] = $record;
            }
        }

        throw new ProvisioningException("{$name} is not in the Openprovider account.");
    }

    private function id(string $domain): int
    {
        return (int) ($this->domain($domain)['id'] ?? throw new ProvisioningException("Openprovider returned no ID for {$domain}."));
    }

    /** @param  array<string, mixed>  $changes */
    private function update(string $domain, array $changes): void
    {
        $this->client->call('PUT', 'domains/'.$this->id($domain), ['domain' => self::split($domain), ...$changes]);
        unset($this->domains[self::normal($domain)]);
    }

    /**
     * @param  list<string>  $nameservers
     * @return array<string, mixed> name_servers, or Openprovider's own group when there are none
     */
    private function nameserverBody(array $nameservers): array
    {
        $nameservers = array_values(array_filter(array_map('trim', $nameservers)));

        return $nameservers === []
            ? ['ns_group' => self::DEFAULT_NS_GROUP]
            : ['name_servers' => array_map(fn (string $ns, int $i) => ['name' => $ns, 'seq_nr' => $i + 1], $nameservers, array_keys($nameservers))];
    }

    private function createCustomer(DomainContact $contact): string
    {
        $handle = $this->client->call('POST', 'customers', $this->customer($contact))['handle'] ?? null;

        if (! is_string($handle) || $handle === '') {
            throw new ProvisioningException('Openprovider returned no contact handle.');
        }

        return $handle;
    }

    /** @return array<string, mixed> a customer's fields, the phone as +CC, area code (first 3 digits) and number */
    private function customer(DomainContact $contact): array
    {
        [$street, $number] = self::streetAndNumber($contact->address1);
        $phone = EppPhone::format($contact->phone, $contact->country);
        [$code, $digits] = str_contains($phone, '.') ? explode('.', $phone, 2) : ['', preg_replace('/\D/', '', $phone)];

        return array_filter([
            'name' => ['first_name' => $contact->first_name, 'last_name' => $contact->last_name],
            'company_name' => $contact->company,
            'email' => $contact->email,
            'phone' => ['country_code' => $code, 'area_code' => substr($digits, 0, 3), 'subscriber_number' => substr($digits, 3)],
            'address' => array_filter([
                'street' => trim($street.($contact->address2 !== '' ? ', '.$contact->address2 : '')),
                'number' => $number,
                'zipcode' => $contact->postcode,
                'city' => $contact->city,
                'state' => $contact->state,
                'country' => $contact->country,
            ], fn ($value) => $value !== ''),
        ], fn ($value) => $value !== '');
    }

    /** @param  array<string, mixed>  $customer */
    private static function contact(array $customer): DomainContact
    {
        $address = $customer['address'] ?? [];
        $phone = $customer['phone'] ?? [];
        $number = ($phone['area_code'] ?? '').($phone['subscriber_number'] ?? '');

        return DomainContact::fromArray([
            'first_name' => $customer['name']['first_name'] ?? '',
            'last_name' => $customer['name']['last_name'] ?? '',
            'company' => $customer['company_name'] ?? '',
            'address1' => trim(($address['street'] ?? '').' '.($address['number'] ?? '')),
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'postcode' => $address['zipcode'] ?? '',
            'country' => $address['country'] ?? '',
            'phone' => $number !== '' ? ($phone['country_code'] ?? '').'.'.$number : '',
            'email' => $customer['email'] ?? '',
        ]);
    }

    private static function normal(string $domain): string
    {
        return strtolower(rtrim(trim($domain), '.'));
    }
}
