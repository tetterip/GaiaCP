<?php

namespace Plugins\SiteChecks;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

/** gaiacp:check-sites: every active hosting account's domain, DNS and HTTPS (daily at 05:00 while the plugin is on). */
class CheckSites extends Command
{
    protected $signature = 'gaiacp:check-sites';

    protected $description = 'Check that every active hosting account\'s domain points to its server and has a valid HTTPS certificate';

    public function handle(PluginManager $plugins, SiteCheckRunner $runner): int
    {
        if (! $plugins->enabled('site-checks')) {
            $this->info('The Site checks plugin is off.');

            return self::SUCCESS;
        }

        $summary = $runner->run();
        $this->info("{$summary['sites']} site(s), {$summary['problems']} with a problem, ".count($summary['new']).' new.');

        foreach ($summary['new'] as $problem) {
            $this->line("  {$problem}");
        }

        return self::SUCCESS;
    }
}
