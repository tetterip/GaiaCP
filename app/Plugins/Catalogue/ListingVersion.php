<?php

namespace App\Plugins\Catalogue;

use App\Plugins\PluginManager;
use Carbon\CarbonImmutable;

/** One version of a plugin in the catalogue: its zip (absolute https URL, SHA-256 and size) and what it runs on. */
final readonly class ListingVersion
{
    public function __construct(
        public string $version,
        public ?string $requires,
        public ?string $php,
        public string $url,
        public string $sha256,
        public int $size,
        public ?CarbonImmutable $releasedAt,
        public string $changelog,
    ) {}

    /** Runs on this GaiaCP and this PHP. */
    public function runsHere(): bool
    {
        return $this->missing() === null;
    }

    /** What this install lacks to run it, e.g. "GaiaCP 0.9.0" or "PHP 8.4"; null when it runs here. */
    public function missing(): ?string
    {
        return match (true) {
            ! PluginManager::runsOn($this->requires) => 'GaiaCP '.$this->requires,
            $this->php !== null && version_compare(PHP_VERSION, $this->php, '<') => 'PHP '.$this->php,
            default => null,
        };
    }
}
