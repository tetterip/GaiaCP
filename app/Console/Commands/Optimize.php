<?php

namespace App\Console\Commands;

use App\Support\Health\SpeedCaches;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:optimize')]
#[Description('Cache config, routes, events, views and Filament components (php artisan optimize), logged; what Optimize now on System health does')]
class Optimize extends Command
{
    public function handle(SpeedCaches $caches): int
    {
        if (! $caches->build()) {
            $this->error('Not cached: '.$caches->lastFailure());

            return self::FAILURE;
        }

        $this->info('Config, routes, events, views and Filament components are cached.');

        return self::SUCCESS;
    }
}
