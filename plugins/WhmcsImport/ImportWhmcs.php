<?php

namespace Plugins\WhmcsImport;

use App\Plugins\PluginManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:import-whmcs
    {--host= : WHMCS database host (default WHMCS_DB_HOST)}
    {--port= : Port (default WHMCS_DB_PORT, 3306)}
    {--database= : Database name (default WHMCS_DB_DATABASE)}
    {--username= : Database user (default WHMCS_DB_USERNAME)}
    {--password= : Database password (default WHMCS_DB_PASSWORD)}
    {--hash= : cc_encryption_hash from WHMCS\'s configuration.php, to import passwords (default WHMCS_ENCRYPTION_HASH)}
    {--api-url= : WHMCS URL, to have WHMCS decrypt the passwords GaiaCP can\'t (default WHMCS_API_URL)}
    {--api-identifier= : WHMCS API credential identifier (default WHMCS_API_IDENTIFIER)}
    {--api-secret= : WHMCS API credential secret (default WHMCS_API_SECRET)}
    {--without=* : Leave a part out (repeat for several): server, product, client, hosting, domain, billable, invoice}
    {--without-invoices : Do not import unpaid invoices as payment notices (same as --without=invoice)}
    {--dry-run : Show what would be imported and the problems found, without saving anything}')]
#[Description('Import servers, products, clients, services, domains and unpaid invoices from a WHMCS database (read-only there; safe to re-run)')]
class ImportWhmcs extends Command
{
    public function handle(): int
    {
        if (! app(PluginManager::class)->enabled('whmcs-import')) {
            $this->error('The WHMCS import plugin is disabled. Enable it under Settings → Plugins.');

            return self::FAILURE;
        }

        $without = array_map(fn (string $part) => rtrim(strtolower(trim($part)), 's'), $this->option('without'));
        $unknown = array_diff($without, array_keys(WhmcsImport::PARTS));

        if ($unknown !== []) {
            $this->error('Unknown part(s): '.implode(', ', $unknown).'. Use: '.implode(', ', array_keys(WhmcsImport::PARTS)).'.');

            return self::INVALID;
        }

        if ($this->option('without-invoices')) {
            $without[] = 'invoice';
        }

        WhmcsConnection::configure(array_filter([
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'database' => $this->option('database'),
            'username' => $this->option('username'),
            'password' => $this->option('password'),
        ], fn ($value) => $value !== null));

        $hash = $this->option('hash') ?? config('database.connections.whmcs.encryption_hash');
        $report = (new WhmcsImport(WhmcsConnection::NAME, $hash, WhmcsApi::make(
            $this->option('api-url') ?? config('database.connections.whmcs.api_url'),
            $this->option('api-identifier') ?? config('database.connections.whmcs.api_identifier'),
            $this->option('api-secret') ?? config('database.connections.whmcs.api_secret'),
        )))->run($this->option('dry-run'), array_values(array_diff(array_keys(WhmcsImport::PARTS), $without)));

        $this->table(
            ['', 'New', 'Already here (linked)', 'Imported before', 'Skipped'],
            array_map(fn (array $row) => [$row['label'], $row['created'], $row['matched'], $row['existing'], $row['skipped']], $report->rows()),
        );

        foreach ($report->problems as $problem) {
            $this->warn($problem);
        }

        $this->info(($report->dryRun ? 'Dry run, nothing saved: would import ' : 'Imported ').$report->summary().'.');

        return self::SUCCESS;
    }
}
