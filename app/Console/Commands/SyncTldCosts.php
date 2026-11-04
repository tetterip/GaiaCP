<?php

namespace App\Console\Commands;

use App\Actions\ImportTldPrices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:sync-tld-costs {--dry-run : List the costs that would change without saving them}')]
#[Description('Read what each registrar charges for its TLDs and update their costs (Settings → Domains → Update registrar costs daily)')]
class SyncTldCosts extends Command
{
    public function handle(ImportTldPrices $import): int
    {
        if (! config('gaiacp.domains.sync_costs') && ! $this->option('dry-run')) {
            $this->info('Off (Settings → Domains → Update registrar costs daily).');

            return self::SUCCESS;
        }

        $report = $import->syncCosts((bool) $this->option('dry-run'));

        foreach ($report['changed'] as $line) {
            $this->line($line);
        }

        foreach ($report['losses'] as $tld) {
            $this->warn("{$tld} now sells at a loss");
        }

        foreach ($report['failed'] as $line) {
            $this->error($line);
        }

        $this->info(sprintf('%d %s', count($report['changed']), $this->option('dry-run') ? 'to update' : 'updated'));

        return self::SUCCESS;
    }
}
