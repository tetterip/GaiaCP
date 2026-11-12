<?php

namespace Plugins\ResellerClub;

use App\Models\Client;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ManagesRegistrarLock;
use App\Provisioning\ProvisioningException;
use App\Support\EppPhone;
use App\Support\Region;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * ResellerClub's calls: domains/available, domains/register, domains/transfer, domains/renew, domains/details-by-name
 * (options=All: endtime, ns1…, domsecret = EPP code, orderstatus, contacts, cns), domains/modify-ns,
 * domains/modify-contact, domains/add-cns / modify-cns-ip / delete-cns-ip, domains/enable- / disable-theft-protection
 * (the lock), customers/details + customers/v2/signup (each client is a customer, found by email) and contacts/add.
 * Contacts are of type "Contact"; TLDs that need their own kind (.eu, .uk, .ca, …) are not covered yet.
 */
class ResellerClubRegistrar implements ChecksAvailability, DomainRegistrar, ManagesChildNameservers, ManagesDomainContacts, ManagesRegistrarLock
{
    /** ResellerClub's prefix for each of GaiaCP's roles (DomainContact::ROLES): registrantcontact, reg-contact-id, … */
    public const ROLES = [
        'registrant' => ['registrantcontact', 'reg-contact-id'],
        'admin' => ['admincontact', 'admin-contact-id'],
        'tech' => ['techcontact', 'tech-contact-id'],
        'billing' => ['billingcontact', 'billing-contact-id'],
    ];

    /** @var array<string, array<string, mixed>> domains read in this run, by name */
    private array $domains = [];

    public function __construct(private ResellerClubClient $client) {}

    public function name(): string
    {
        return 'ResellerClub';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function available(string $domain): bool
    {
        [$sld, $tld] = self::split($domain);
        $status = $this->client->call('GET', 'domains/available', ['domain-name' => $sld, 'tlds' => $tld], timeout: 15)[self::normal($domain)]['status'] ?? null;

        if (! is_string($status)) {
            throw new ProvisioningException("ResellerClub returned no availability for {$domain}.");
        }

        return strtolower($status) === 'available';
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $end = $this->domain($domain)['endtime'] ?? null;

        if (! is_numeric($end)) {
            throw new ProvisioningException("ResellerClub returned no expiry date for {$domain}.");
        }

        // A Unix time: its calendar day in GaiaCP's timezone (Settings → General → Region).
        return CarbonImmutable::parse(CarbonImmutable::createFromTimestamp((int) $end, Region::timezone())->toDateString());
    }

    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        [$customer, $contact] = $this->customerAndContact($registrant);

        $this->client->call('POST', 'domains/register', [
            'domain-name' => self::normal($domain),
            'years' => $years,
            'ns' => $nameservers !== [] ? $nameservers : null,
            'customer-id' => $customer,
            ...self::contactIds($contact),
            'invoice-option' => 'NoInvoice',
            'protect-privacy' => false,
        ], timeout: 120);
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        [$customer, $contact] = $this->customerAndContact($registrant);

        $this->client->call('POST', 'domains/transfer', [
            'domain-name' => self::normal($domain),
            'auth-code' => $eppCode,
            'customer-id' => $customer,
            ...self::contactIds($contact),
            'invoice-option' => 'NoInvoice',
        ], secrets: [$eppCode], timeout: 120);
    }

    /** ResellerClub wants the current expiry (exp-date) so a repeated call can't renew twice. */
    public function renew(string $domain, int $years): void
    {
        $record = $this->domain($domain);

        $this->client->call('POST', 'domains/renew', [
            'order-id' => $this->orderId($domain),
            'years' => $years,
            'exp-date' => $record['endtime'] ?? null,
            'invoice-option' => 'NoInvoice',
        ], timeout: 120);
        unset($this->domains[self::normal($domain)]);
    }

    public function nameservers(string $domain): array
    {
        $record = $this->domain($domain);
        $nameservers = [];

        for ($i = 1; $i <= 13; $i++) {
            if (filled($record["ns{$i}"] ?? null)) {
                $nameservers[] = strtolower(trim((string) $record["ns{$i}"]));
            }
        }

        return $nameservers;
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        if ($nameservers === []) {
            throw new ProvisioningException('ResellerClub needs at least two nameservers.');
        }

        $this->client->call('POST', 'domains/modify-ns', ['order-id' => $this->orderId($domain), 'ns' => $nameservers]);
        unset($this->domains[self::normal($domain)]);
    }

    public function eppCode(string $domain): string
    {
        $code = $this->domain($domain)['domsecret'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new ProvisioningException("ResellerClub returned no EPP code for {$domain}.");
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

        foreach (self::ROLES as $role => [$field]) {
            if (is_array($record[$field] ?? null)) {
                $contacts[$role] = self::contact($record[$field]);
            }
        }

        return $contacts;
    }

    /** Each new contact becomes a contact of the domain's customer (one per distinct set of details); a role not given keeps its own. */
    public function saveContacts(string $domain, array $contacts): void
    {
        $record = $this->domain($domain);
        $customer = (string) ($record['customerid'] ?? throw new ProvisioningException("ResellerClub returned no customer for {$domain}."));
        $params = ['order-id' => $this->orderId($domain)];
        $made = [];

        foreach (self::ROLES as $role => [$field, $param]) {
            if (isset($contacts[$role])) {
                $key = serialize($contacts[$role]->toArray());
                $params[$param] = $made[$key] ??= $this->addContact($customer, $contacts[$role]);
            } else {
                $params[$param] = $record[$field.'id'] ?? null;
            }
        }

        $this->client->call('POST', 'domains/modify-contact', $params);
        unset($this->domains[self::normal($domain)]);
    }

    public function saveChildNameserver(string $domain, string $host, string $ip, ?string $oldIp = null): void
    {
        $oldIp !== null && $oldIp !== $ip
            ? $this->client->call('POST', 'domains/modify-cns-ip', ['order-id' => $this->orderId($domain), 'cns' => self::normal($host), 'old-ip' => $oldIp, 'new-ip' => $ip])
            : $this->client->call('POST', 'domains/add-cns', ['order-id' => $this->orderId($domain), 'cns' => self::normal($host), 'ip' => $ip]);
        unset($this->domains[self::normal($domain)]);
    }

    /** delete-cns-ip takes the IPs to remove: the ones ResellerClub lists for the host. */
    public function deleteChildNameserver(string $domain, string $host): void
    {
        $ips = (array) ($this->domain($domain)['cns'][self::normal($host)] ?? []);

        if ($ips === []) {
            throw new ProvisioningException("ResellerClub has no nameserver {$host}.");
        }

        $this->client->call('POST', 'domains/delete-cns-ip', ['order-id' => $this->orderId($domain), 'cns' => self::normal($host), 'ip' => array_values($ips)]);
        unset($this->domains[self::normal($domain)]);
    }

    public function locked(string $domain): bool
    {
        return in_array('transferlock', array_map('strtolower', (array) ($this->domain($domain)['orderstatus'] ?? [])), true);
    }

    public function setLocked(string $domain, bool $locked): void
    {
        $this->client->call('POST', $locked ? 'domains/enable-theft-protection' : 'domains/disable-theft-protection', ['order-id' => $this->orderId($domain)]);
        unset($this->domains[self::normal($domain)]);
    }

    /** @return array{0: string, 1: string} "acme.co.uk" → acme, co.uk */
    public static function split(string $domain): array
    {
        $domain = self::normal($domain);

        if (! str_contains($domain, '.')) {
            throw new ProvisioningException("{$domain} is not a domain name.");
        }

        return explode('.', $domain, 2);
    }

    /** @return array<string, mixed> */
    private function domain(string $domain): array
    {
        $name = self::normal($domain);

        return $this->domains[$name] ??= $this->client->call('GET', 'domains/details-by-name', ['domain-name' => $name, 'options' => 'All']);
    }

    private function orderId(string $domain): string
    {
        $id = $this->domain($domain)['orderid'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : throw new ProvisioningException("ResellerClub returned no order for {$domain}.");
    }

    /**
     * The client's customer at ResellerClub (found by email, else signed up with a random password they never need),
     * and a new contact of it with the client's details.
     *
     * @return array{0: string, 1: string}
     */
    private function customerAndContact(Client $client): array
    {
        $contact = DomainContact::fromClient($client);

        try {
            $customer = (string) ($this->client->call('GET', 'customers/details', ['username' => $contact->email])['customerid'] ?? '');
        } catch (ProvisioningException) {
            $customer = '';
        }

        if ($customer === '') {
            $customer = (string) ($this->client->call('POST', 'customers/v2/signup', [
                'username' => $contact->email,
                'passwd' => Str::password(16, symbols: false).'a1A',
                ...$this->contactFields($contact, withEmail: false),
                'lang-pref' => 'en',
            ])['value'] ?? '');
        }

        if ($customer === '') {
            throw new ProvisioningException('ResellerClub returned no customer ID.');
        }

        return [$customer, $this->addContact($customer, $contact)];
    }

    private function addContact(string $customer, DomainContact $contact): string
    {
        $id = (string) ($this->client->call('POST', 'contacts/add', [...$this->contactFields($contact), 'customer-id' => $customer, 'type' => 'Contact'])['value'] ?? '');

        return $id !== '' ? $id : throw new ProvisioningException('ResellerClub returned no contact ID.');
    }

    /** @return array<string, string> the same contact in all four roles */
    private static function contactIds(string $contact): array
    {
        return array_fill_keys(array_column(self::ROLES, 1), $contact);
    }

    /** @return array<string, string> ResellerClub's contact fields: the company is required (the person's name without one) */
    private function contactFields(DomainContact $contact, bool $withEmail = true): array
    {
        $phone = EppPhone::format($contact->phone, $contact->country);
        [$code, $number] = str_contains($phone, '.') ? explode('.', ltrim($phone, '+'), 2) : ['', preg_replace('/\D/', '', $phone)];

        return array_filter([
            'name' => trim("{$contact->first_name} {$contact->last_name}"),
            'company' => $contact->company !== '' ? $contact->company : 'N/A',
            'email' => $withEmail ? $contact->email : '',
            'address-line-1' => $contact->address1,
            'address-line-2' => $contact->address2,
            'city' => $contact->city,
            'state' => $contact->state,
            'country' => $contact->country,
            'zipcode' => $contact->postcode,
            'phone-cc' => (string) $code,
            'phone' => (string) $number,
        ], fn (string $value) => $value !== '');
    }

    /** @param  array<string, mixed>  $contact */
    private static function contact(array $contact): DomainContact
    {
        $name = trim((string) ($contact['name'] ?? ''));
        [$first, $last] = str_contains($name, ' ') ? explode(' ', $name, 2) : [$name, ''];
        $company = (string) ($contact['company'] ?? '');

        return DomainContact::fromArray([
            'first_name' => $first,
            'last_name' => $last,
            'company' => $company === 'N/A' ? '' : $company,
            'address1' => $contact['address1'] ?? '',
            'address2' => $contact['address2'] ?? '',
            'city' => $contact['city'] ?? '',
            'state' => $contact['state'] ?? '',
            'postcode' => $contact['zip'] ?? '',
            'country' => $contact['country'] ?? '',
            'phone' => filled($contact['telno'] ?? null) ? '+'.($contact['telnocc'] ?? '').'.'.$contact['telno'] : '',
            'email' => $contact['emailaddr'] ?? '',
        ]);
    }

    private static function normal(string $domain): string
    {
        return strtolower(rtrim(trim($domain), '.'));
    }
}
