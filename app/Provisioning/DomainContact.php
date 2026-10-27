<?php

namespace App\Provisioning;

use App\Models\Client;

/**
 * One contact of a domain at its registrar (registrant, admin, tech or billing), as read from or sent to a
 * ManagesDomainContacts registrar. The phone is as typed; each registrar formats it (App\Support\EppPhone).
 */
final readonly class DomainContact
{
    /** The roles a registrar may have, in the order screens show them (label()). */
    public const ROLES = ['registrant' => 'Owner (registrant)', 'admin' => 'Administrative', 'tech' => 'Technical', 'billing' => 'Billing'];

    public const FIELDS = ['first_name', 'last_name', 'company', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phone', 'email'];

    public function __construct(
        public string $first_name = '',
        public string $last_name = '',
        public string $company = '',
        public string $address1 = '',
        public string $address2 = '',
        public string $city = '',
        public string $state = '',
        public string $postcode = '',
        public string $country = '',
        public string $phone = '',
        public string $email = '',
    ) {}

    public static function fromClient(Client $client): self
    {
        return self::fromArray($client->only(self::FIELDS));
    }

    /** @param  array<string, mixed>  $values */
    public static function fromArray(array $values): self
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = trim((string) ($values[$field] ?? ''));
        }
        $fields['country'] = strtoupper($fields['country']);

        return new self(...$fields);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function label(string $role): string
    {
        return match ($role) {
            'registrant' => __('Owner (registrant)'),
            'admin' => __('Administrative'),
            'tech' => __('Technical'),
            'billing' => __('Billing'),
            default => $role,
        };
    }

    /** The company, else the person: what a registrar with a single name field gets. */
    public function name(): string
    {
        return $this->company !== '' ? $this->company : trim("{$this->first_name} {$this->last_name}");
    }
}
