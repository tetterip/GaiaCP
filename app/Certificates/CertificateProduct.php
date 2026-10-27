<?php

namespace App\Certificates;

/** One certificate a provider sells. */
final readonly class CertificateProduct
{
    /**
     * @param  list<int>  $periods  order lengths it accepts, in months
     * @param  list<string>  $methods  the validation methods it accepts: dns, http, https, email
     */
    public function __construct(
        public string $code,
        public string $name,
        public bool $wildcard = false,
        public bool $organization = false,
        public array $periods = [12],
        public array $methods = ['dns', 'http', 'email'],
    ) {}

    /** @return array<string, mixed> for the cache (plain arrays only) */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['code'], (string) $data['name'], (bool) ($data['wildcard'] ?? false), (bool) ($data['organization'] ?? false),
            array_map('intval', (array) ($data['periods'] ?? [12])), array_values((array) ($data['methods'] ?? ['dns', 'http', 'email'])));
    }
}
