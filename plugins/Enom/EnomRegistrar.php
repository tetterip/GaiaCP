<?php

namespace Plugins\Enom;

use App\Models\Client;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\EmailsEppCode;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ManagesRegistrarLock;
use App\Provisioning\ProvisioningException;
use App\Support\EppPhone;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Enom's reseller API commands, with the field names WHMCS-era modules send (Check, Purchase, Extend, TP_CreateOrder,
 * GetDomainInfo, GetDNS, ModifyNS, SynchAuthInfo, GetContacts, Contacts, RegisterNameServer, UpdateNameServer,
 * DeleteNameServer, GetRegLock, SetRegLock). A domain goes as SLD (the first label) and TLD (the rest).
 * Enom does not hand back EPP codes: it emails them to the registrant (EmailsEppCode).
 */
class EnomRegistrar implements ChecksAvailability, DomainRegistrar, EmailsEppCode, ManagesChildNameservers, ManagesDomainContacts, ManagesRegistrarLock
{
    /** Enom takes NS1..NS12. */
    public const MAX_NAMESERVERS = 12;

    /** Contact prefixes on Purchase: the client is all four, as WHMCS does by default. */
    public const CONTACTS = ['Registrant', 'Admin', 'Tech', 'AuxBilling'];

    /** Enom's contact prefix for each of GaiaCP's roles (DomainContact::ROLES). */
    public const ROLES = ['registrant' => 'Registrant', 'admin' => 'Admin', 'tech' => 'Tech', 'billing' => 'AuxBilling'];

    public function __construct(private EnomClient $client) {}

    public function name(): string
    {
        return 'Enom';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /** Check answers RRPCode 210 when the domain can be registered, 211 when it is taken. */
    public function available(string $domain): bool
    {
        $code = (string) ($this->client->call('Check', self::split($domain), timeout: 15)->RRPCode ?? '');

        return match ($code) {
            '210' => true,
            '211' => false,
            default => throw new ProvisioningException("Enom Check returned an unexpected response for {$domain}."),
        };
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $expiration = (string) ($this->client->call('GetDomainInfo', self::split($domain))->GetDomainInfo->status->expiration ?? '');

        try {
            // "3/15/2027 12:00:00 AM" (US order); keep its calendar date.
            return CarbonImmutable::parse(CarbonImmutable::parse($expiration)->toDateString());
        } catch (Throwable) {
            throw new ProvisioningException("Enom returned no expiry date for {$domain}.");
        }
    }

    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $params = [...self::split($domain), 'NumYears' => $years, 'IgnoreNSFail' => 'Yes', ...$this->nameserverParams($nameservers)];

        if ($nameservers === []) {
            $params['UseDNS'] = 'default'; // Enom's own nameservers
        }

        foreach (self::CONTACTS as $prefix) {
            foreach ($this->contact($registrant) as $field => $value) {
                $params[$prefix.$field] = $value;
            }
        }

        $this->client->call('Purchase', $params, timeout: 120);
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        ['SLD' => $sld, 'TLD' => $tld] = self::split($domain);
        $params = ['OrderType' => 'Autoverification', 'DomainCount' => 1, 'SLD1' => $sld, 'TLD1' => $tld, 'AuthInfo1' => $eppCode, 'UseContacts' => 0];

        foreach ($this->contact($registrant) as $field => $value) {
            $params['Registrant'.$field] = $value;
        }

        $this->client->call('TP_CreateOrder', $params, secrets: [$eppCode], timeout: 120);
    }

    public function renew(string $domain, int $years): void
    {
        $this->client->call('Extend', [...self::split($domain), 'NumYears' => $years], timeout: 120);
    }

    public function nameservers(string $domain): array
    {
        $xml = $this->client->call('GetDNS', self::split($domain));

        return array_values(array_filter(array_map(fn ($ns) => strtolower(trim((string) $ns)), $xml->xpath('//dns') ?: [])));
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $this->client->call('ModifyNS', [...self::split($domain), ...$this->nameserverParams($nameservers)]);
    }

    /** Asks Enom to email the code to the registrant; there is no code to return. */
    public function eppCode(string $domain): string
    {
        $this->client->call('SynchAuthInfo', [...self::split($domain), 'EmailEPP' => 'True', 'RunSynchAutoInfo' => 'True']);

        return '';
    }

    public function contactRoles(): array
    {
        return array_keys(self::ROLES);
    }

    public function contacts(string $domain): array
    {
        $xml = $this->client->call('GetContacts', self::split($domain));
        $contacts = [];

        foreach (self::ROLES as $role => $prefix) {
            $node = $xml->GetContacts->{$prefix} ?? null;

            if ($node === null || $node->count() === 0) {
                continue;
            }

            $read = fn (string $field) => trim((string) ($node->{$prefix.$field} ?? ''));
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

    /** One Contacts call per role, as Enom takes one ContactType at a time. */
    public function saveContacts(string $domain, array $contacts): void
    {
        foreach (self::ROLES as $role => $prefix) {
            if (! isset($contacts[$role])) {
                continue;
            }

            $params = [...self::split($domain), 'ContactType' => strtoupper($prefix)];

            foreach ($this->contactFields($contacts[$role]) as $field => $value) {
                $params[$prefix.$field] = $value;
            }

            $this->client->call('Contacts', $params);
        }
    }

    public function saveChildNameserver(string $domain, string $host, string $ip, ?string $oldIp = null): void
    {
        $oldIp !== null && $oldIp !== $ip
            ? $this->client->call('UpdateNameServer', ['NS' => $host, 'OldIP' => $oldIp, 'NewIP' => $ip])
            : $this->client->call('RegisterNameServer', ['Add' => 'true', 'NSName' => $host, 'IP' => $ip]);
    }

    public function deleteChildNameserver(string $domain, string $host): void
    {
        $this->client->call('DeleteNameServer', ['NS' => $host]);
    }

    public function locked(string $domain): bool
    {
        return (int) ($this->client->call('GetRegLock', self::split($domain))->{'reg-lock'} ?? 0) === 1;
    }

    public function setLocked(string $domain, bool $locked): void
    {
        $this->client->call('SetRegLock', [...self::split($domain), 'UnlockRegistrar' => $locked ? 0 : 1]);
    }

    /** @return array{SLD: string, TLD: string} "acme.com.gr" → acme, com.gr */
    public static function split(string $domain): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        if (! str_contains($domain, '.')) {
            throw new ProvisioningException("{$domain} is not a domain name.");
        }

        [$sld, $tld] = explode('.', $domain, 2);

        return ['SLD' => $sld, 'TLD' => $tld];
    }

    /**
     * @param  list<string>  $nameservers
     * @return array<string, string> NS1, NS2, … for the ones given
     */
    private function nameserverParams(array $nameservers): array
    {
        $nameservers = array_values(array_filter(array_map('trim', $nameservers)));

        if (count($nameservers) > self::MAX_NAMESERVERS) {
            throw new ProvisioningException('Enom accepts at most '.self::MAX_NAMESERVERS.' nameservers.');
        }

        $params = [];

        foreach ($nameservers as $i => $nameserver) {
            $params['NS'.($i + 1)] = $nameserver;
        }

        return $params;
    }

    /**
     * Contact fields without the Registrant / Admin / Tech / AuxBilling prefix. With a company, Enom also wants a job
     * title and a fax number: "N/A" and the phone, as other Enom modules send.
     *
     * @return array<string, string>
     */
    private function contact(Client $client): array
    {
        return $this->contactFields(DomainContact::fromClient($client));
    }

    /** @return array<string, string> */
    private function contactFields(DomainContact $contact): array
    {
        $phone = EppPhone::format($contact->phone, $contact->country);
        $fields = [
            'FirstName' => $contact->first_name,
            'LastName' => $contact->last_name,
            'Address1' => $contact->address1,
            'Address2' => $contact->address2,
            'City' => $contact->city,
            'StateProvince' => $contact->state,
            'PostalCode' => $contact->postcode,
            'Country' => $contact->country,
            'EmailAddress' => $contact->email,
            'Phone' => $phone,
        ];

        if ($contact->company !== '') {
            $fields += ['OrganizationName' => $contact->company, 'JobTitle' => 'N/A', 'Fax' => $phone];
        }

        return $fields;
    }
}
