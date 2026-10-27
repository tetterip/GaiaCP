<?php

namespace App\Console\Commands;

use App\Actions\CompareServerAccounts;
use App\Filament\Resources\Servers\Pages\CompareServer;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Provisioning\ProvisioningException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('gaiacp:compare-servers {--dry-run : List what is new without telling the admins}')]
#[Description('Compare every active server\'s accounts with GaiaCP (Servers → Compare with the server) and tell the admins what is new')]
class CompareServers extends Command
{
    public function handle(CompareServerAccounts $compare): int
    {
        if (! config('gaiacp.automation.compare_servers')) {
            $this->info('The nightly server comparison is off (Settings → Automation).');

            return self::SUCCESS;
        }

        $servers = Server::query()->where('active', true)->orderBy('name')->get()
            ->filter(fn (Server $server) => CompareServer::canList($server));

        foreach ($servers as $server) {
            try {
                $problems = CompareServerAccounts::problems($compare->handle($server));
            } catch (ProvisioningException $e) {
                // Not read: what was told before still stands. A server that is down is the monitoring's job.
                $this->warn("{$server->name}: {$e->getMessage()}");

                continue;
            }

            // Only what wasn't there last time; something fixed and back again is new again.
            $new = array_diff_key($problems, array_flip($server->compare_alerted ?? []));
            $this->line(sprintf('%s: %d to look at, %d new', $server->name, count($problems), count($new)));

            foreach ($new as $problem) {
                $this->line("  {$problem}");
            }

            if ($this->option('dry-run')) {
                continue;
            }

            $server->forceFill(['compare_alerted' => array_keys($problems)])->save();

            if ($new !== []) {
                ActivityLog::record('server.accounts_differ', Str::limit("{$server->name}: ".implode('; ', $new), 250), properties: [
                    'server_id' => $server->id,
                    'count' => count($new),
                    'items' => array_values($new),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
