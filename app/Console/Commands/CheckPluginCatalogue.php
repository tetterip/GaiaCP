<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Plugins\Catalogue\Catalogue;
use App\Plugins\Catalogue\CatalogueException;
use App\Plugins\Catalogue\CatalogueInstaller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:check-plugin-catalogue')]
#[Description('Read the plugin catalogue and ring the bell once for each new version of an installed plugin')]
class CheckPluginCatalogue extends Command
{
    public function handle(Catalogue $catalogue, CatalogueInstaller $installer): int
    {
        if (! Catalogue::enabled()) {
            $this->info('The plugin catalogue is off.');

            return self::SUCCESS;
        }

        try {
            $data = $catalogue->fetch();
        } catch (CatalogueException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS; // the next run tries again; the page shows the last copy read
        }

        foreach ($installer->updates($data) as $update) {
            $plugin = $update['plugin'];
            $version = $update['version']->version;

            $told = ActivityLog::query()->where('event', 'plugin.update_available')
                ->where('properties->plugin', $plugin->id())->where('properties->version', $version)->exists();

            if (! $told) {
                ActivityLog::record('plugin.update_available', "Plugin {$plugin->name()} {$version} is in the catalogue (installed: {$plugin->version()})", properties: [
                    'plugin' => $plugin->id(),
                    'name' => $plugin->name(),
                    'version' => $version,
                    'installed' => $plugin->version(),
                ]);
            }

            $this->line("{$plugin->name()}: {$plugin->version()} → {$version}".($told ? ' (told before)' : ''));
        }

        $this->info(count($data->plugins).' plugins in the catalogue.');

        return self::SUCCESS;
    }
}
