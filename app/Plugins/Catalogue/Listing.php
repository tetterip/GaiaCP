<?php

namespace App\Plugins\Catalogue;

/** A plugin in the catalogue: what Settings → Plugins shows about it, and its versions, newest first. */
final readonly class Listing
{
    /**
     * @param  array<string, string>  $description  locale => text, `en` always there
     * @param  list<ListingVersion>  $versions  newest first
     */
    public function __construct(
        public string $folder,
        public string $class,
        public string $name,
        public array $description,
        public string $category,
        public string $author,
        public ?string $authorUrl,
        public ?string $homepage,
        public ?string $licence,
        public bool $official,
        public array $versions,
    ) {}

    public function description(?string $locale = null): string
    {
        return $this->description[$locale ?? app()->getLocale()] ?? $this->description['en'];
    }

    public function latest(): ListingVersion
    {
        return $this->versions[0];
    }

    /** The newest version that runs on this GaiaCP and PHP, or null when every one needs something newer. */
    public function best(): ?ListingVersion
    {
        foreach ($this->versions as $version) {
            if ($version->runsHere()) {
                return $version;
            }
        }

        return null;
    }
}
