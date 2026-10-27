<?php

namespace App\Console\Commands;

use App\Support\Demo;
use App\Support\Version;
use App\Updates\Updater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('gaiacp:update {--check : Only say whether a newer release is out}')]
#[Description('Update GaiaCP to the latest release: back up the database, install the new code, run the migrations')]
class Update extends Command
{
    public function handle(Updater $updater): int
    {
        if (Demo::enabled()) {
            $this->error('The demo is updated by deploying its Git checkout, not from releases.');

            return self::FAILURE;
        }

        try {
            $release = $updater->latest(fresh: true);
        } catch (Throwable $e) {
            $this->error('Could not check for updates: '.$e->getMessage());

            return self::FAILURE;
        }

        $current = Version::current();

        if ($release === null || ! $updater->isNewer($release)) {
            $this->info("GaiaCP {$current} is up to date.");

            return self::SUCCESS;
        }

        $this->info("GaiaCP {$release->version} is available (this is {$current}).");
        if (filled($release->notes)) {
            $this->line(trim($release->notes));
        }

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        if ($updater->isGitCheckout()) {
            $this->warn('This copy is a Git checkout: update it by deploying (git pull, composer install, migrate).');

            return self::FAILURE;
        }

        if ($this->input->isInteractive() && ! $this->confirm("Back up the database and update to {$release->version} now?", true)) {
            return self::SUCCESS;
        }

        try {
            $updater->update($release, fn (string $step) => $this->line("  {$step}..."));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Updated to GaiaCP {$release->version}.");

        return self::SUCCESS;
    }
}
