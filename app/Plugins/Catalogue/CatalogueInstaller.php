<?php

namespace App\Plugins\Catalogue;

use App\Plugins\Plugin;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\File;

/**
 * Installs and updates plugins from the catalogue (Settings → Plugins → Plugin catalogue): the newest version that runs
 * here is downloaded, checked against the signed catalogue (Catalogue::download()) and handed to PluginInstaller,
 * which makes the same checks as an uploaded zip. A catalogue plugin is an uploaded plugin like any other.
 */
class CatalogueInstaller
{
    public function __construct(private Catalogue $catalogue, private PluginManager $plugins, private PluginInstaller $installer) {}

    /**
     * Installs the plugin, or updates it to its newest version that runs here.
     *
     * @throws CatalogueException|PluginInstallException
     */
    public function install(Listing $listing): Plugin
    {
        $version = $listing->best();
        if ($version === null) {
            throw new CatalogueException(__('No version of :plugin runs on this GaiaCP yet.', ['plugin' => $listing->name]));
        }

        $installed = $this->installed($listing);
        if ($installed !== null && $this->plugins->bundled($installed)) {
            throw new PluginInstallException(__('This is a plugin bundled with GaiaCP: it updates together with GaiaCP.'));
        }

        $zip = $this->catalogue->download($version);

        try {
            return $this->installer->install($zip);
        } finally {
            File::delete($zip);
        }
    }

    /** The plugin in plugins/<Folder> of this listing, or null when it is not installed. */
    public function installed(Listing $listing): ?Plugin
    {
        return $this->plugins->all()->first(fn (Plugin $plugin) => strcasecmp(basename($plugin->path()), $listing->folder) === 0);
    }

    /** Whether the catalogue has a newer version of this installed, uploaded plugin that runs here. */
    public function updateFor(Listing $listing): ?ListingVersion
    {
        $installed = $this->installed($listing);
        $best = $listing->best();

        if ($installed === null || $best === null || $this->plugins->bundled($installed)) {
            return null;
        }

        return version_compare($best->version, $installed->version(), '>') ? $best : null;
    }

    /** @return list<array{plugin: Plugin, listing: Listing, version: ListingVersion}> the installed plugins with an update */
    public function updates(CatalogueData $catalogue): array
    {
        $updates = [];

        foreach ($catalogue->plugins as $listing) {
            if ($version = $this->updateFor($listing)) {
                $updates[] = ['plugin' => $this->installed($listing), 'listing' => $listing, 'version' => $version];
            }
        }

        return $updates;
    }
}
