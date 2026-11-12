<?php

namespace Plugins\NameSilo;

use App\Models\Client;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\EmailsEppCode;
use App\Provisioning\ListsTldPrices;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ManagesRegistrarLock;
use App\Provisioning\ProvisioningException;
use App\Provisioning\TldPrice;
use App\Support\EppPhone;
use Carbon\CarbonImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * NameSilo's API operations: checkRegisterAvailability, registerDomain, transferDomain, renewDomain, getDomainInfo,
 * changeNameServers, retrieveAuthCode (NameSilo emails the code to the domain's administrative contact:
 * EmailsEppCode), contactList / contactAdd / contactDomainAssociate, add / modify / deleteRegisteredNameServer,
 * domainLock / domainUnlock, and getPrices (every TLD, 1 year, USD). Contacts are records with an ID that domains point to.
 */
class NameSiloRegistrar implements ChecksAvailability, DomainRegistrar, EmailsEppCode, ListsTldPrices, ManagesChildNameservers, ManagesDomainContacts, ManagesRegistrarLock
{
    /** NameSilo takes ns1..ns13. */
    public const MAX_NAMESERVERS = 13;

    /** NameSilo's name for each of GaiaCP's roles (DomainContact::ROLES), in getDomainInfo's contact_ids and contactDomainAssociate. */
    public const ROLES = ['registrant' => 'registrant', 'admin' => 'administrative', 'tech' => 'technical', 'billing' => 'billing'];

    public function __construct(private NameSiloClient $client) {}

    public function name(): string
    {
        return 'NameSilo';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /** A premium name costs more than the TLD's price: GaiaCP can't sell it at that price, so it counts as taken. */
    public function available(string $domain): bool
    {
        $domain = self::normal($domain);
        $reply = $this->client->call('checkRegisterAvailability', ['domains' => $domain], timeout: 15);

        foreach ($reply->available->domain ?? [] as $free) {
            if (strtolower(trim((string) $free)) === $domain) {
                return ! in_array(strtolower((string) ($free['premium'] ?? '0')), ['1', 'true', 'yes'], true);
            }
        }

        foreach (['unavailable', 'invalid'] as $list) {
            foreach ($reply->{$list}->domain ?? [] as $taken) {
                if (strtolower(trim((string) $taken)) === $domain) {
                    return false;
                }
            }
        }

        throw new ProvisioningException("NameSilo returned no availability for {$domain}.");
    }

    /** getPrices lists every TLD in one reply. */
    public function listsAllTlds(): bool
    {
        return true;
    }

    public function tldPrices(?array $tlds = null): array
    {
        $wanted = $tlds === null ? null : array_map(fn (string $tld) => ltrim(mb_strtolower(trim($tld)), '.'), $tlds);
        $prices = [];

        foreach ($this->client->call('getPrices', timeout: 60)->children() as $node) {
            $tld = strtolower($node->getName());

            if ($node->count() === 0 || in_array($tld, ['code', 'detail'], true) || ($wanted !== null && ! in_array($tld, $wanted, true))) {
                continue;
            }

            $price = new TldPrice(
                tld: '.'.$tld,
                years: 1,
                currency: 'USD',
                registerCents: TldPrice::cents((string) ($node->registration ?? '')),
                renewCents: TldPrice::cents((string) ($node->renew ?? '')),
                transferCents: TldPrice::cents((string) ($node->transfer ?? '')),
            );

            if ($price->registerCents !== null || $price->renewCents !== null || $price->transferCents !== null) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $expires = trim((string) ($this->info($domain)->expires ?? ''));

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $expires) ?: throw new ProvisioningException('');
        } catch (Throwable) {
            throw new ProvisioningException("NameSilo returned no expiry date for {$domain}.");
        }
    }

    /** Privacy (WHOIS privacy, free at NameSilo) on and auto renew off: GaiaCP renews when the client pays. */
    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $this->client->call('registerDomain', [
            'domain' => self::normal($domain),
            'years' => $years,
            'private' => 1,
            'auto_renew' => 0,
            ...$this->nameserverParams($nameservers),
            ...$this->contactFields(DomainContact::fromClient($registrant)),
        ], timeout: 120);
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        $this->client->call('transferDomain', [
            'domain' => self::normal($domain),
            'auth' => $eppCode,
            'private' => 1,
            'auto_renew' => 0,
            ...$this->contactFields(DomainContact::fromClient($registrant)),
        ], secrets: [$eppCode], timeout: 120);
    }

    public function renew(string $domain, int $years): void
    {
        $this->client->call('renewDomain', ['domain' => self::normal($domain), 'years' => $years], timeout: 120);
    }

    public function nameservers(string $domain): array
    {
        $nameservers = [];

        foreach ($this->info($domain)->nameservers->nameserver ?? [] as $nameserver) {
            if (trim((string) $nameserver) !== '') {
                $nameservers[(int) ($nameserver['position'] ?? count($nameservers) + 1)] = strtolower(trim((string) $nameserver));
            }
        }

        ksort($nameservers);

        return array_values($nameservers);
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        if ($nameservers === []) {
            throw new ProvisioningException('NameSilo needs at least two nameservers.');
        }

        $this->client->call('changeNameServers', ['domain' => self::normal($domain), ...$this->nameserverParams($nameservers)]);
    }

    /** Asks NameSilo to email the code to the domain's administrative contact; there is no code to return. */
    public function eppCode(string $domain): string
    {
        $this->client->call('retrieveAuthCode', ['domain' => self::normal($domain)]);

        return '';
    }

    public function contactRoles(): array
    {
        return array_keys(self::ROLES);
    }

    public function contacts(string $domain): array
    {
        $ids = $this->contactIds($domain);
        $read = [];
        $contacts = [];

        foreach (self::ROLES as $role => $name) {
            $id = $ids[$role] ?? null;

            if ($id === null) {
                continue;
            }

            $read[$id] ??= $this->contact($id);

            if ($read[$id] !== null) {
                $contacts[$role] = $read[$id];
            }
        }

        return $contacts;
    }

    /**
     * NameSilo keeps contacts as records that domains point to, and one record may serve several domains: each new
     * contact becomes a new record (contactAdd), then the domain points its roles at them (contactDomainAssociate).
     */
    public function saveContacts(string $domain, array $contacts): void
    {
        $associate = ['domain' => self::normal($domain)];
        $made = [];

        foreach (self::ROLES as $role => $name) {
            if (! isset($contacts[$role])) {
                continue;
            }

            $fields = $this->contactFields($contacts[$role]);
            $key = serialize($fields);
            $made[$key] ??= trim((string) $this->client->call('contactAdd', $fields)->contact_id);

            if ($made[$key] === '') {
                throw new ProvisioningException("NameSilo returned no contact ID for {$domain}.");
            }

            $associate[$name] = $made[$key];
        }

        if (count($associate) > 1) {
            $this->client->call('contactDomainAssociate', $associate);
        }
    }

    public function saveChildNameserver(string $domain, string $host, string $ip, ?string $oldIp = null): void
    {
        $label = self::hostLabel($domain, $host);

        $oldIp !== null
            ? $this->client->call('modifyRegisteredNameServer', ['domain' => self::normal($domain), 'current_host' => $label, 'new_host' => $label, 'ip1' => $ip])
            : $this->client->call('addRegisteredNameServer', ['domain' => self::normal($domain), 'new_host' => $label, 'ip1' => $ip]);
    }

    public function deleteChildNameserver(string $domain, string $host): void
    {
        $this->client->call('deleteRegisteredNameServer', ['domain' => self::normal($domain), 'current_host' => self::hostLabel($domain, $host)]);
    }

    public function locked(string $domain): bool
    {
        return in_array(strtolower(trim((string) ($this->info($domain)->locked ?? ''))), ['yes', '1', 'true'], true);
    }

    public function setLocked(string $domain, bool $locked): void
    {
        $this->client->call($locked ? 'domainLock' : 'domainUnlock', ['domain' => self::normal($domain)]);
    }

    /** "ns1.acme.com" under acme.com → "ns1": NameSilo takes the host without its domain. */
    public static function hostLabel(string $domain, string $host): string
    {
        $domain = self::normal($domain);
        $host = self::normal($host);

        return str_ends_with($host, '.'.$domain) ? substr($host, 0, -strlen('.'.$domain)) : $host;
    }

    private function info(string $domain): SimpleXMLElement
    {
        return $this->client->call('getDomainInfo', ['domain' => self::normal($domain)]);
    }

    /** @return array<string, string> contact record ID by role */
    private function contactIds(string $domain): array
    {
        $node = $this->info($domain)->contact_ids;
        $ids = [];

        foreach (self::ROLES as $role => $name) {
            $id = trim((string) ($node->{$name} ?? ''));

            if ($id !== '') {
                $ids[$role] = $id;
            }
        }

        return $ids;
    }

    private function contact(string $id): ?DomainContact
    {
        $node = $this->client->call('contactList', ['contact_id' => $id])->contact ?? null;

        if ($node === null || $node->count() === 0) {
            return null;
        }

        $read = fn (string $field) => trim((string) ($node->{$field} ?? ''));

        return DomainContact::fromArray([
            'first_name' => $read('first_name'),
            'last_name' => $read('last_name'),
            'company' => $read('company'),
            'address1' => $read('address'),
            'address2' => $read('address2'),
            'city' => $read('city'),
            'state' => $read('state'),
            'postcode' => $read('zip'),
            'country' => $read('country'),
            'phone' => $read('phone'),
            'email' => $read('email'),
        ]);
    }

    private static function normal(string $domain): string
    {
        return strtolower(rtrim(trim($domain), '.'));
    }

    /**
     * @param  list<string>  $nameservers
     * @return array<string, string> ns1, ns2, … for the ones given
     */
    private function nameserverParams(array $nameservers): array
    {
        $nameservers = array_values(array_filter(array_map('trim', $nameservers)));

        if (count($nameservers) > self::MAX_NAMESERVERS) {
            throw new ProvisioningException('NameSilo accepts at most '.self::MAX_NAMESERVERS.' nameservers.');
        }

        $params = [];

        foreach ($nameservers as $i => $nameserver) {
            $params['ns'.($i + 1)] = $nameserver;
        }

        return $params;
    }

    /**
     * contactAdd's and registerDomain's fields. NameSilo takes the phone as digits only, with the calling code.
     *
     * @return array<string, string>
     */
    private function contactFields(DomainContact $contact): array
    {
        $fields = [
            'fn' => $contact->first_name,
            'ln' => $contact->last_name,
            'ad' => $contact->address1,
            'cy' => $contact->city,
            'st' => $contact->state,
            'zp' => $contact->postcode,
            'ct' => $contact->country,
            'em' => $contact->email,
            'ph' => preg_replace('/\D/', '', EppPhone::format($contact->phone, $contact->country)),
        ];

        if ($contact->address2 !== '') {
            $fields['ad2'] = $contact->address2;
        }

        if ($contact->company !== '') {
            $fields['nn'] = $contact->company;
        }

        return $fields;
    }
}
