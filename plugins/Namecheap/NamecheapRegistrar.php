<?php

namespace Plugins\Namecheap;

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
 * Namecheap's API commands (namecheap.domains.*): check, create, transfer.create, renew, getInfo, dns.getList /
 * setCustom / setDefault, getContacts / setContacts, ns.create / update / delete, getRegistrarLock / setRegistrarLock,
 * and users.getPricing for the costs (USD, 1 year). Commands that take the domain in two parts get SLD (the first label)
 * and TLD (the rest). Namecheap's API has no command that gives out a domain's EPP code: it is in Namecheap's own panel.
 */
class NamecheapRegistrar implements ChecksAvailability, DomainRegistrar, ListsTldPrices, ManagesChildNameservers, ManagesDomainContacts, ManagesRegistrarLock
{
    /** Namecheap takes at most 12 nameservers. */
    public const MAX_NAMESERVERS = 12;

    /** Namecheap's contact prefix for each of GaiaCP's roles (DomainContact::ROLES). */
    public const ROLES = ['registrant' => 'Registrant', 'admin' => 'Admin', 'tech' => 'Tech', 'billing' => 'AuxBilling'];

    /** users.getPricing's ProductCategory for each kind of price. */
    public const CATEGORIES = ['register' => 'REGISTER', 'renew' => 'RENEW', 'transfer' => 'TRANSFER'];

    public function __construct(private NamecheapClient $client) {}

    public function name(): string
    {
        return 'Namecheap';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function available(string $domain): bool
    {
        $result = $this->client->call('namecheap.domains.check', ['DomainList' => self::normal($domain)], timeout: 15)->DomainCheckResult ?? null;
        $available = strtolower((string) ($result['Available'] ?? ''));

        if (! in_array($available, ['true', 'false'], true)) {
            throw new ProvisioningException("Namecheap returned no availability for {$domain}.");
        }

        // A premium name costs more than the TLD's price: GaiaCP can't sell it at that price, so it counts as taken.
        return $available === 'true' && strtolower((string) ($result['IsPremiumName'] ?? 'false')) !== 'true';
    }

    /** users.getPricing lists every TLD of a category in one reply. */
    public function listsAllTlds(): bool
    {
        return true;
    }

    public function tldPrices(?array $tlds = null): array
    {
        $wanted = $tlds === null ? null : array_map(fn (string $tld) => ltrim(mb_strtolower(trim($tld)), '.'), $tlds);
        $prices = [];

        foreach (self::CATEGORIES as $kind => $category) {
            $products = $this->client->call('namecheap.users.getPricing', ['ProductType' => 'DOMAIN', 'ProductCategory' => $category], timeout: 120)
                ->xpath("//*[local-name()='Product']") ?: [];

            foreach ($products as $product) {
                $tld = strtolower(trim((string) $product['Name']));

                if ($tld === '' || ($wanted !== null && ! in_array($tld, $wanted, true))) {
                    continue;
                }

                foreach ($product->children() as $price) {
                    if ((int) $price['Duration'] === 1 && strtoupper((string) ($price['DurationType'] ?? 'YEAR')) === 'YEAR') {
                        $prices[$tld][$kind] = TldPrice::cents((string) ($price['YourPrice'] ?? $price['Price'] ?? ''));
                        $prices[$tld]['currency'] = strtoupper((string) ($price['Currency'] ?? 'USD')) ?: 'USD';
                    }
                }
            }
        }

        $list = [];

        foreach ($prices as $tld => $price) {
            if (($price['register'] ?? null) === null && ($price['renew'] ?? null) === null && ($price['transfer'] ?? null) === null) {
                continue;
            }

            $list[] = new TldPrice(
                tld: '.'.$tld,
                years: 1,
                currency: $price['currency'],
                registerCents: $price['register'] ?? null,
                renewCents: $price['renew'] ?? null,
                transferCents: $price['transfer'] ?? null,
            );
        }

        return $list;
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $expired = trim((string) ($this->client->call('namecheap.domains.getInfo', ['DomainName' => self::normal($domain)])
            ->DomainGetInfoResult->DomainDetails->ExpiredDate ?? ''));

        try {
            // "03/15/2027" (US order).
            return CarbonImmutable::createFromFormat('!m/d/Y', $expired) ?: throw new ProvisioningException('');
        } catch (Throwable) {
            throw new ProvisioningException("Namecheap returned no expiry date for {$domain}.");
        }
    }

    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $params = ['DomainName' => self::normal($domain), 'Years' => $years, 'AddFreeWhoisguard' => 'yes', 'WGEnabled' => 'no'];

        if ($nameservers !== []) {
            $params['Nameservers'] = $this->nameserverList($nameservers);
        }

        $params += $this->contactParams(array_fill_keys(array_keys(self::ROLES), DomainContact::fromClient($registrant)));

        $result = $this->client->call('namecheap.domains.create', $params, timeout: 120)->DomainCreateResult ?? null;

        if (strtolower((string) ($result['Registered'] ?? '')) !== 'true') {
            throw new ProvisioningException("Namecheap did not register {$domain}.");
        }
    }

    /**
     * Namecheap takes the contacts from the domain's current registrar, then they can be changed under Contact details.
     * The EPP code goes base64 encoded with a "base64:" prefix, as Namecheap's module sends it (codes with symbols).
     */
    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        $encoded = 'base64:'.base64_encode($eppCode);
        $result = $this->client->call('namecheap.domains.transfer.create', ['DomainName' => self::normal($domain), 'Years' => 1, 'EPPCode' => $encoded], secrets: [$eppCode, $encoded], timeout: 120)
            ->DomainTransferCreateResult ?? null;

        if (strtolower((string) ($result['Transfer'] ?? '')) !== 'true') {
            throw new ProvisioningException("Namecheap did not start the transfer of {$domain}: ".((string) ($result['StatusDescription'] ?? '') ?: 'no reason given'));
        }
    }

    public function renew(string $domain, int $years): void
    {
        $result = $this->client->call('namecheap.domains.renew', ['DomainName' => self::normal($domain), 'Years' => $years], timeout: 120)->DomainRenewResult ?? null;

        if (strtolower((string) ($result['Renew'] ?? '')) !== 'true') {
            throw new ProvisioningException("Namecheap did not renew {$domain}.");
        }
    }

    public function nameservers(string $domain): array
    {
        $result = $this->client->call('namecheap.domains.dns.getList', self::split($domain))->DomainDNSGetListResult ?? null;
        $nameservers = [];

        foreach ($result?->children() ?? [] as $nameserver) {
            if ($nameserver->getName() === 'Nameserver' && trim((string) $nameserver) !== '') {
                $nameservers[] = strtolower(trim((string) $nameserver));
            }
        }

        return $nameservers;
    }

    /** No nameservers = Namecheap's own (dns.setDefault). */
    public function setNameservers(string $domain, array $nameservers): void
    {
        $nameservers === []
            ? $this->client->call('namecheap.domains.dns.setDefault', self::split($domain))
            : $this->client->call('namecheap.domains.dns.setCustom', [...self::split($domain), 'Nameservers' => $this->nameserverList($nameservers)]);
    }

    public function eppCode(string $domain): string
    {
        throw new ProvisioningException('Namecheap does not give out EPP codes through its API: get it in your Namecheap account (Domain List → Manage → Sharing & Transfer).');
    }

    public function contactRoles(): array
    {
        return array_keys(self::ROLES);
    }

    public function contacts(string $domain): array
    {
        $result = $this->client->call('namecheap.domains.getContacts', ['DomainName' => self::normal($domain)])->DomainContactsResult ?? null;
        $contacts = [];

        foreach (self::ROLES as $role => $prefix) {
            $node = $result?->{$prefix};

            if ($node === null || $node->count() === 0) {
                continue;
            }

            $read = fn (string $field) => trim((string) ($node->{$field} ?? ''));
            $contacts[$role] = DomainContact::fromArray([
                'first_name' => $read('FirstName'),
                'last_name' => $read('LastName'),
                'company' => $read('OrganizationName'),
                'address1' => $read('Address1'),
                'address2' => $read('Address2'),
                'city' => $read('City'),
                'state' => $read('StateProvince'),
                'postcode' => $read('PostalCode'),
                'country' => $read('Country'),
                'phone' => $read('Phone'),
                'email' => $read('EmailAddress'),
            ]);
        }

        return $contacts;
    }

    /** setContacts wants all four contacts: a role not given keeps what Namecheap has. */
    public function saveContacts(string $domain, array $contacts): void
    {
        $missing = array_diff_key(self::ROLES, $contacts);

        if ($missing !== []) {
            $contacts += array_intersect_key($this->contacts($domain), $missing);
        }

        if (array_diff_key(self::ROLES, $contacts) !== []) {
            throw new ProvisioningException("Namecheap needs all four contacts of {$domain}.");
        }

        $this->client->call('namecheap.domains.setContacts', ['DomainName' => self::normal($domain), ...$this->contactParams($contacts)]);
    }

    public function saveChildNameserver(string $domain, string $host, string $ip, ?string $oldIp = null): void
    {
        $oldIp !== null && $oldIp !== $ip
            ? $this->client->call('namecheap.domains.ns.update', [...self::split($domain), 'Nameserver' => $host, 'OldIP' => $oldIp, 'IP' => $ip])
            : $this->client->call('namecheap.domains.ns.create', [...self::split($domain), 'Nameserver' => $host, 'IP' => $ip]);
    }

    public function deleteChildNameserver(string $domain, string $host): void
    {
        $this->client->call('namecheap.domains.ns.delete', [...self::split($domain), 'Nameserver' => $host]);
    }

    public function locked(string $domain): bool
    {
        $result = $this->client->call('namecheap.domains.getRegistrarLock', ['DomainName' => self::normal($domain)])->DomainGetRegistrarLockResult ?? null;

        return strtolower((string) ($result['RegistrarLockStatus'] ?? '')) === 'true';
    }

    public function setLocked(string $domain, bool $locked): void
    {
        $this->client->call('namecheap.domains.setRegistrarLock', ['DomainName' => self::normal($domain), 'LockAction' => $locked ? 'LOCK' : 'UNLOCK']);
    }

    /** @return array{SLD: string, TLD: string} "acme.co.uk" → acme, co.uk */
    public static function split(string $domain): array
    {
        $domain = self::normal($domain);

        if (! str_contains($domain, '.')) {
            throw new ProvisioningException("{$domain} is not a domain name.");
        }

        [$sld, $tld] = explode('.', $domain, 2);

        return ['SLD' => $sld, 'TLD' => $tld];
    }

    private static function normal(string $domain): string
    {
        return strtolower(rtrim(trim($domain), '.'));
    }

    /** @param  list<string>  $nameservers  "ns1.host.com,ns2.host.com" */
    private function nameserverList(array $nameservers): string
    {
        $nameservers = array_values(array_filter(array_map('trim', $nameservers)));

        if (count($nameservers) > self::MAX_NAMESERVERS) {
            throw new ProvisioningException('Namecheap accepts at most '.self::MAX_NAMESERVERS.' nameservers.');
        }

        return implode(',', $nameservers);
    }

    /**
     * @param  array<string, DomainContact>  $contacts  by role
     * @return array<string, string> RegistrantFirstName, AdminFirstName, …
     */
    private function contactParams(array $contacts): array
    {
        $params = [];

        foreach (self::ROLES as $role => $prefix) {
            if (! isset($contacts[$role])) {
                continue;
            }

            foreach ($this->contactFields($contacts[$role]) as $field => $value) {
                $params[$prefix.$field] = $value;
            }
        }

        return $params;
    }

    /** @return array<string, string> */
    private function contactFields(DomainContact $contact): array
    {
        $fields = [
            'FirstName' => $contact->first_name,
            'LastName' => $contact->last_name,
            'Address1' => $contact->address1,
            'City' => $contact->city,
            'StateProvince' => $contact->state,
            'PostalCode' => $contact->postcode,
            'Country' => $contact->country,
            'Phone' => EppPhone::format($contact->phone, $contact->country),
            'EmailAddress' => $contact->email,
        ];

        if ($contact->address2 !== '') {
            $fields['Address2'] = $contact->address2;
        }

        if ($contact->company !== '') {
            $fields['OrganizationName'] = $contact->company;
        }

        return $fields;
    }
}
