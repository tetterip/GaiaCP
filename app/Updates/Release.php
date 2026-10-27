<?php

namespace App\Updates;

use Carbon\CarbonImmutable;

/** A published GaiaCP release: its version, notes and the zip (with its SHA-256 file and that file's signature) to update from. */
final readonly class Release
{
    public function __construct(
        public string $version,
        public string $notes,
        public ?CarbonImmutable $publishedAt,
        public string $zipUrl,
        public ?string $checksumUrl,
        public string $pageUrl,
        public ?string $signatureUrl = null,
    ) {}

    /** @return array<string, mixed> for the cache and Livewire */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'notes' => $this->notes,
            'published_at' => $this->publishedAt?->toIso8601String(),
            'zip_url' => $this->zipUrl,
            'checksum_url' => $this->checksumUrl,
            'page_url' => $this->pageUrl,
            'signature_url' => $this->signatureUrl,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['version'],
            $data['notes'],
            $data['published_at'] ? CarbonImmutable::parse($data['published_at']) : null,
            $data['zip_url'],
            $data['checksum_url'],
            $data['page_url'],
            $data['signature_url'] ?? null,
        );
    }
}
