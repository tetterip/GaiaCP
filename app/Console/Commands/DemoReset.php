<?php

namespace App\Console\Commands;

use App\Support\Demo;
use App\Support\DemoSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('gaiacp:demo-reset {--rebuild : Build the demo data again first, even if today\'s is ready}')]
#[Description('Put the public demo\'s made-up data back (GAIACP_DEMO=true only; every few minutes from the scheduler)')]
class DemoReset extends Command
{
    public function handle(DemoSnapshot $snapshot): int
    {
        if (! Demo::enabled()) {
            $this->error('This is not the demo (GAIACP_DEMO=true): nothing was changed.');

            return self::FAILURE;
        }

        try {
            if ($this->option('rebuild') || ! $snapshot->current()) {
                $this->line('Building the demo data…');
                $snapshot->build();
            }

            $snapshot->restore();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Demo data put back. Next reset: '.Demo::nextReset()->format('H:i'));

        return self::SUCCESS;
    }
}
