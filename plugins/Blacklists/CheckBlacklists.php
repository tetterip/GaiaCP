<?php

namespace Plugins\Blacklists;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

/** gaiacp:check-blacklists: every active server's IP against the DNS blacklists (hourly while the plugin is on). */
class CheckBlacklists extends Command
{
    protected $signature = 'gaiacp:check-blacklists';

    protected $description = 'Look up every active server\'s IP in the DNS blacklists of Settings → IP blacklist check';

    public function handle(PluginManager $plugins, BlacklistCheck $check): int
    {
        if (! $plugins->enabled('blacklists')) {
            $this->info('The IP blacklist check plugin is off.');

            return self::SUCCESS;
        }

        $summary = $check->run();
        $this->info("{$summary['servers']} server(s), {$summary['ips']} IP(s): {$summary['listed']} listing(s).");

        foreach ($summary['refused'] as $zone) {
            $this->warn("{$zone} did not answer the question (a public DNS resolver is refused by some lists).");
        }

        return self::SUCCESS;
    }
}
