<?php

namespace App\Provisioning;

/** One WordPress site on a hosting account, as its panel's WordPress manager lists it (ManagesWordPress). */
final class WordPressSite
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $version = null,
    ) {}

    /** @return array{id: string, url: string, title: ?string, version: ?string} plain, for the cache */
    public function toArray(): array
    {
        return ['id' => $this->id, 'url' => $this->url, 'title' => $this->title, 'version' => $this->version];
    }

    /** @param  array<string, mixed>  $site */
    public static function fromArray(array $site): self
    {
        return new self((string) $site['id'], (string) $site['url'], $site['title'] ?? null, $site['version'] ?? null);
    }
}
