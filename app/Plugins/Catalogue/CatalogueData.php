<?php

namespace App\Plugins\Catalogue;

use Carbon\CarbonImmutable;

/** A verified catalogue: when it was built and the plugins in it. */
final readonly class CatalogueData
{
    /** @param  list<Listing>  $plugins */
    public function __construct(
        public CarbonImmutable $generatedAt,
        public array $plugins,
    ) {}

    public function find(string $folder): ?Listing
    {
        foreach ($this->plugins as $plugin) {
            if (strcasecmp($plugin->folder, $folder) === 0) {
                return $plugin;
            }
        }

        return null;
    }
}
