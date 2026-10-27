<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Support\Version;
use App\Updates\Updater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('gaiacp:check-for-update')]
#[Description('Ask GitHub for the latest GaiaCP release and ring the bell once for each version newer than this one')]
class CheckForUpdate extends Command
{
    public function handle(Updater $updater): int
    {
        if (! config('gaiacp.updates.check')) {
            $this->info('Checking for updates is off (GAIACP_UPDATE_CHECK).');

            return self::SUCCESS;
        }

        try {
            $release = $updater->latest(fresh: true);
        } catch (RuntimeException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS; // tomorrow's run tries again; Settings → Updates shows the error
        }

        if ($release === null || ! $updater->isNewer($release)) {
            $this->info('GaiaCP '.Version::current().' is the latest.');

            return self::SUCCESS;
        }

        $told = ActivityLog::query()->where('event', 'gaiacp.update_available')
            ->where('properties->version', $release->version)->exists();

        if (! $told) {
            ActivityLog::record('gaiacp.update_available', "GaiaCP {$release->version} is out (installed: ".Version::current().')', properties: [
                'version' => $release->version,
                'installed' => Version::current(),
                'git' => $updater->isGitCheckout(),
            ]);
        }

        $this->line('GaiaCP '.Version::current()." → {$release->version}".($told ? ' (told before)' : ''));

        return self::SUCCESS;
    }
}
