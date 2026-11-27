<?php

namespace Tests\Support\TestRegistrar;

use App\Models\Client;
use App\Provisioning\ChecksAvailability;
use App\Provisioning\DomainContact;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ListsTldPrices;
use App\Provisioning\ManagesChildNameservers;
use App\Provisioning\ManagesDomainContacts;
use App\Provisioning\ProvisioningException;
use App\Provisioning\TldPrice;
use App\Support\EppPhone;
use Carbon\CarbonImmutable;
use Tests\FakesRegistrar;

/**
 * The registrar GaiaCP's own tests use (Tests\FakesRegistrar): an in-memory account that answers like a real one
 * would, so the domain features (orders, renewals, nameservers, contacts, TLD prices) are tested without a plugin
 * from the catalogue. Each call is recorded as [action, params] on the test, with the params a registrar API is sent.
 */
class TestRegistrar implements ChecksAvailability, DomainRegistrar, ListsTldPrices, ManagesChildNameservers, ManagesDomainContacts
{
    public const AVAILABILITY = 'available';

    public const EPP_CODE = 'epp_code';

    public const GET_NAMESERVERS = 'nameservers';

    public const SAVE_NAMESERVERS = 'set_nameservers';

    public const REGISTER = 'register';

    public const RENEW = 'renew';

    public const GET_CONTACTS = 'contacts';

    public const SAVE_CONTACTS = 'save_contacts';

    public const SAVE_CHILD_NAMESERVER = 'save_child_nameserver';

    public const DELETE_CHILD_NAMESERVER = 'delete_child_nameserver';

    public const TRANSFER = 'transfer';

    public const SYNC = 'sync';

    public const TLD_PRICES = 'tld_prices';

    public const CONTACT_ROLES = ['registrant', 'admin', 'tech'];

    public const MAX_NAMESERVERS = 4;

    /** @param  object&FakesRegistrar  $account  the test holding the account's state */
    public function __construct(private object $account, private array $credentials) {}

    public function name(): string
    {
        return 'Test registrar';
    }

    public function configured(): bool
    {
        return filled($this->credentials['username'] ?? null) && filled($this->credentials['password'] ?? null);
    }

    public function available(string $domain): bool
    {
        $this->call(self::AVAILABILITY, ['domain' => $domain]);

        return ! isset($this->account->registrarDomains[$domain]);
    }

    public function listsAllTlds(): bool
    {
        return true;
    }

    public function tldPrices(?array $tlds = null): array
    {
        $this->call(self::TLD_PRICES);
        $wanted = $tlds === null ? null : array_map(fn (string $tld) => '.'.ltrim(mb_strtolower(trim($tld)), '.'), $tlds);
        $prices = [];

        foreach ($this->account->registrarTldPrices as $entry) {
            $tld = '.'.ltrim(mb_strtolower(trim((string) $entry['tld'])), '.');

            if ($wanted !== null && ! in_array($tld, $wanted, true)) {
                continue;
            }

            $prices[] = new TldPrice(
                tld: $tld,
                years: max(1, (int) round((float) ($entry['min_period'] ?? 1))),
                currency: 'EUR',
                registerCents: TldPrice::cents($entry['min_buy'] ?? null),
                renewCents: TldPrice::cents($entry['min_ren'] ?? null),
                transferCents: TldPrice::cents($entry['transfer'] ?? null),
                redemptionCents: TldPrice::cents($entry['redemption'] ?? null),
            );
        }

        return $prices;
    }

    public function expiryDate(string $domain): CarbonImmutable
    {
        $this->call(self::SYNC, ['domain' => $domain]);

        return CarbonImmutable::parse($this->held($domain)['expires']);
    }

    public function register(string $domain, int $years, Client $registrant, array $nameservers): void
    {
        $this->assertContactComplete($registrant);
        $params = ['domain' => $domain, 'years' => (string) $years, ...$this->nameserverParams($nameservers), ...$this->contactFields(DomainContact::fromClient($registrant))];
        $this->call(self::REGISTER, $params);

        $this->account->registrarDomains[$domain] = [
            'expires' => $this->account->registrarNoExpiryYet ? '0000-00-00 00:00:00' : now()->addYears($years)->toDateString(),
            'ns' => array_values(array_filter([$params['ns1'], $params['ns2'], $params['ns3'], $params['ns4']])),
        ];
    }

    public function transfer(string $domain, string $eppCode, Client $registrant): void
    {
        $this->assertContactComplete($registrant);
        $this->call(self::TRANSFER, ['domain' => $domain, 'pw' => $eppCode, ...$this->contactFields(DomainContact::fromClient($registrant))]);
    }

    public function renew(string $domain, int $years): void
    {
        $this->call(self::RENEW, ['domain' => $domain, 'years' => (string) $years]);
        $held = $this->held($domain);
        $this->account->registrarDomains[$domain]['expires'] = CarbonImmutable::parse($held['expires'])->addYears($years)->toDateString();
    }

    public function nameservers(string $domain): array
    {
        $this->call(self::GET_NAMESERVERS, ['domain' => $domain]);

        return array_values(array_map('strtolower', $this->held($domain)['ns'] ?? []));
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $params = ['domain' => $domain, ...$this->nameserverParams($nameservers)];
        $this->call(self::SAVE_NAMESERVERS, $params);
        $this->account->registrarDomains[$domain]['ns'] = array_values(array_filter([$params['ns1'], $params['ns2'], $params['ns3'], $params['ns4']]));
    }

    public function eppCode(string $domain): string
    {
        $this->call(self::EPP_CODE, ['domain' => $domain]);

        return $this->held($domain)['epp'] ?? 'Epp-Code-1';
    }

    public function contactRoles(): array
    {
        return self::CONTACT_ROLES;
    }

    public function contacts(string $domain): array
    {
        $this->call(self::GET_CONTACTS, ['domain' => $domain]);
        $this->held($domain);
        $contact = fn (string $name) => DomainContact::fromArray(['first_name' => $name, 'last_name' => 'Papa', 'address1' => 'Ermou 1', 'city' => 'Athens',
            'postcode' => '10563', 'state' => 'Attica', 'phone' => '+30.2101234567', 'email' => 'maria@example.gr', 'country' => 'GR']);

        return $this->account->registrarDomains[$domain]['contacts']
            ?? ['registrant' => $contact('Maria'), 'admin' => $contact('Admin'), 'tech' => $contact('Tech')];
    }

    public function saveContacts(string $domain, array $contacts): void
    {
        $params = ['domain' => $domain];

        foreach (self::CONTACT_ROLES as $role) {
            if (isset($contacts[$role])) {
                $params[$role] = $this->contactFields($contacts[$role]);
            }
        }

        $this->call(self::SAVE_CONTACTS, $params);

        foreach (self::CONTACT_ROLES as $role) {
            if (isset($params[$role])) {
                $this->account->registrarDomains[$domain]['saved_contacts'][$role] = $params[$role];
            }
        }
    }

    public function saveChildNameserver(string $domain, string $host, string $ip, ?string $oldIp = null): void
    {
        $this->call(self::SAVE_CHILD_NAMESERVER, ['domain' => $domain, 'ns' => $host, 'ip' => $ip]);
        $this->account->registrarDomains[$domain]['hosts'][$host] = $ip;
    }

    public function deleteChildNameserver(string $domain, string $host): void
    {
        $this->call(self::DELETE_CHILD_NAMESERVER, ['domain' => $domain, 'ns' => $host]);
        unset($this->account->registrarDomains[$domain]['hosts'][$host]);
    }

    /** Records the call, and fails it as the test asked ($registrarFailures[action] = [code => message]). */
    private function call(string $action, array $params = []): void
    {
        if (! $this->configured()) {
            throw new ProvisioningException('Test registrar is not set up.');
        }

        $this->account->registrarCalls[] = [$action, $params];

        if (isset($this->account->registrarFailures[$action])) {
            throw new ProvisioningException('Test registrar: '.collect($this->account->registrarFailures[$action])->map(fn (string $message, $code) => "{$code} - {$message}")->implode(', '));
        }
    }

    /** @return array{expires: string, ns?: list<string>, epp?: string} */
    private function held(string $domain): array
    {
        return $this->account->registrarDomains[$domain]
            ?? throw new ProvisioningException("Test registrar: 2303 - Object does not exist ({$domain} is not in this Test registrar account)");
    }

    /** @return array<string, string> ns1..ns4, empty strings for unused slots */
    private function nameserverParams(array $nameservers): array
    {
        $nameservers = array_values(array_filter(array_map('trim', $nameservers)));

        if (count($nameservers) > self::MAX_NAMESERVERS) {
            throw new ProvisioningException('Test registrar accepts at most '.self::MAX_NAMESERVERS.' nameservers.');
        }

        $params = [];
        for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
            $params["ns{$i}"] = $nameservers[$i - 1] ?? '';
        }

        return $params;
    }

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
            throw new ProvisioningException("Test registrar needs the client's {$missing->join(', ', ' and ')} for the domain contact: fill them in on the client and try again.");
        }
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
            'voice' => EppPhone::format($contact->phone, $contact->country),
            'email' => $contact->email,
        ];
    }
}
