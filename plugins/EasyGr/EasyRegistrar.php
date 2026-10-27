<?php

namespace Plugins\EasyGr;

use App\Models\Client;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ProvisioningException;
use App\Support\EppPhone;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Port of easy.gr's WHMCS registrar module (modules/registrars/easy/easy.php).
 * Action codes and field names are the module's; keep to them.
 */
class EasyRegistrar implements ChecksAvailability, DomainRegistrar, ManagesChildNameservers, ManagesDomainContacts
{
    /** Whether a domain is free (easy.gr's whoisservers.php for WHMCS). */
    public const AVAILABILITY = 0;

    public const EPP_CODE = 1;

    public const GET_NAMESERVERS = 2;

    public const SAVE_NAMESERVERS = 3;

    public const REGISTER = 4;

    public const RENEW = 5;

    public const GET_CONTACTS = 6;

    public const SAVE_CONTACTS = 7;

    /** Registers a private nameserver, or changes its IP. */
    public const SAVE_CHILD_NAMESERVER = 8;

    public const DELETE_CHILD_NAMESERVER = 10;

    public const TRANSFER = 11;

    /** The contacts easy.gr reads and saves, by their 1..3 suffix (registration also sends a 4th, billing). */
    public const CONTACT_ROLES = [1 => 'registrant', 2 => 'admin', 3 => 'tech'];

    public const SYNC = 19;

    /** The module sends exactly ns1..ns4. */
    public const MAX_NAMESERVERS = 4;

    public function __construct(private EasyClient $client) {}

    public function name(): string
    {
        return 'easy.gr';
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /** Action 0, from easy.gr's whoisservers.php for WHMCS. */
    public function available(string $domain): bool
    {
        return $this->client->available($domain);
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $reply = $this->client->call(self::SYNC, ['domain' => $domain]);

        try {
            // A UTC timestamp; keep its calendar date, as a date in the app's timezone.
            return CarbonImmutable::parse(CarbonImmutable::parse((string) $reply['date_expire'], 'UTC')->toDateString());
        } catch (Throwable) {
            throw new ProvisioningException("easy.gr returned no expiry date for {$domain}.");
        }
    }

    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $this->assertContactComplete($registrant);
        $params = ['domain' => $domain, 'years' => $years, ...$this->nameserverParams($nameservers)];

        // 1 registrant, 2 admin, 3 tech, 4 billing: all the client, as WHMCS does by default.
        foreach ([1, 2, 3, 4] as $index) {
            foreach ($this->contact($registrant) as $field => $value) {
                $params[$field.$index] = $value;
            }
        }

        $this->client->call(self::REGISTER, $params, timeout: 120);
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        $this->assertContactComplete($registrant);
        $this->client->call(self::TRANSFER, ['domain' => $domain, 'pw' => $eppCode, ...$this->contact($registrant)], timeout: 120);
    }

    public function renew(string $domain, int $years): void
    {
        $this->client->call(self::RENEW, ['domain' => $domain, 'years' => $years], timeout: 120);
    }

    public function nameservers(string $domain): array
    {
        $response = $this->client->call(self::GET_NAMESERVERS, ['domain' => $domain])['response'] ?? [];

        if (! is_array($response)) {
            return [];
        }

        // WHMCS reads ns1..ns5 from the reply; fall back to a plain list.
        $keyed = array_filter($response, fn ($key) => is_string($key) && preg_match('/^ns\d+$/', $key), ARRAY_FILTER_USE_KEY);
        uksort($keyed, 'strnatcmp');
        $values = $keyed !== [] ? $keyed : array_filter($response, 'is_int', ARRAY_FILTER_USE_KEY);

        return array_values(array_filter(array_map(fn ($ns) => is_string($ns) ? strtolower(trim($ns)) : '', $values)));
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $this->client->call(self::SAVE_NAMESERVERS, ['domain' => $domain, ...$this->nameserverParams($nameservers)]);
    }

    public function eppCode(string $domain): string
    {
        $code = $this->client->call(self::EPP_CODE, ['domain' => $domain])['response'] ?? null;

        if (! is_scalar($code) || (string) $code === '') {
            throw new ProvisioningException("easy.gr returned no EPP code for {$domain}.");
        }

        return (string) $code;
    }

    public function contactRoles(): array
    {
        return array_values(self::CONTACT_ROLES);
    }

    public function contacts(string $domain): array
    {
        $response = $this->client->call(self::GET_CONTACTS, ['domain' => $domain])['response'] ?? null;

        if (! is_array($response)) {
            throw new ProvisioningException("easy.gr returned no contacts for {$domain}.");
        }

        $contacts = [];

        foreach (self::CONTACT_ROLES as $role) {
            $contact = $response[$role] ?? null;

            if (! is_array($contact)) {
                continue;
            }

            $voice = trim((string) ($contact['voice'] ?? ''));
            $code = trim((string) ($contact['phone_code'] ?? ''), " +\t");
            $contacts[$role] = DomainContact::fromArray([
                'first_name' => $contact['fname'] ?? '',
                'last_name' => $contact['lname'] ?? '',
                'company' => $contact['org'] ?? '',
                'address1' => $contact['street1'] ?? '',
                'address2' => $contact['street2'] ?? '',
                'city' => $contact['city'] ?? '',
                'state' => $contact['sp'] ?? '',
                'postcode' => $contact['pc'] ?? '',
                'country' => $contact['cc'] ?? '',
                'phone' => $code !== '' && $voice !== '' && ! str_starts_with($voice, '+') ? "+{$code}.{$voice}" : $voice,
                'email' => $contact['email'] ?? '',
            ]);
        }

        return $contacts;
    }

    public function saveContacts(string $domain, array $contacts): void
    {
        $params = ['domain' => $domain];

        foreach (self::CONTACT_ROLES as $index => $role) {
            if (! isset($contacts[$role])) {
                continue;
            }

            foreach ($this->contactFields($contacts[$role]) as $field => $value) {
                $params[$field.$index] = $value;
            }
        }

        $this->client->call(self::SAVE_CONTACTS, $params);
    }

    public function saveChildNameserver(string $domain, string $host, string $ip, ?string $oldIp = null): void
    {
        $this->client->call(self::SAVE_CHILD_NAMESERVER, ['domain' => $domain, 'ns' => $host, 'ip' => $ip]);
    }

    public function deleteChildNameserver(string $domain, string $host): void
    {
        $this->client->call(self::DELETE_CHILD_NAMESERVER, ['domain' => $domain, 'ns' => $host]);
    }

    /**
     * @param  list<string>  $nameservers
     * @return array<string, string> ns1..ns4, empty strings for unused slots
     */
    private function nameserverParams(array $nameservers): array
    {
        $nameservers = array_values(array_filter(array_map('trim', $nameservers)));

        if (count($nameservers) > self::MAX_NAMESERVERS) {
            throw new ProvisioningException('easy.gr accepts at most '.self::MAX_NAMESERVERS.' nameservers.');
        }

        $params = [];

        for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
            $params["ns{$i}"] = $nameservers[$i - 1] ?? '';
        }

        return $params;
    }

    /**
     * easy.gr refuses a contact without these (e.g. "sp1 - Συμπλήρωσε την περιοχή"), so say which client fields to
     * fill in before calling it.
     */
    private function assertContactComplete(Client $client): void
    {
        $missing = collect([
            'Address 1' => $client->address1,
            'City' => $client->city,
            'State / region' => $client->state,
            'Country' => $client->country,
            'Phone number' => $client->phone,
            'Email address' => $client->email,
        ])->filter(fn (?string $value) => trim((string) $value) === '')->keys();

        if ($missing->isNotEmpty()) {
            throw new ProvisioningException("easy.gr needs the client's {$missing->join(', ', ' and ')} for the domain contact: fill them in on the client and try again.");
        }
    }

    /**
     * Contact fields without the 1..4 suffix that registration adds.
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
        return [
            'name' => $contact->name(),
            'street1' => $contact->address1,
            'street2' => $contact->address2,
            'city' => $contact->city,
            'pc' => $contact->postcode,
            'sp' => $contact->state,
            'cc' => $contact->country,
            'voice' => self::phone($contact->phone, $contact->country),
            'fax' => '',
            'email' => $contact->email,
        ];
    }

    /** EPP phone format "+30.2101234567" (App\Support\EppPhone), a number without a code taken as the client's country's. */
    public static function phone(?string $phone, ?string $country = null): string
    {
        return EppPhone::format($phone, $country);
    }
}
