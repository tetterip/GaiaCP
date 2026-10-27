<?php

namespace App\Console\Commands;

use App\Support\Health\Check;
use App\Support\Health\Status;
use App\Support\Health\SystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:health {--connections : Also call every active server panel}')]
#[Description('The System health checks (PHP, extensions, database, cron, mail, connections); fails when one is a problem')]
class Health extends Command
{
    public function handle(SystemHealth $health): int
    {
        $checks = $health->checks();
        if ($this->option('connections')) {
            $checks = $checks->concat($health->serverChecks());
        }

        $this->table(['Area', 'Check', 'Status', 'Found', 'What to do'], $checks->map(fn (Check $c) => [
            $c->group, $c->name, $c->status->label(), $c->value, $c->hint,
        ]));

        return $checks->contains(fn (Check $c) => $c->status === Status::Error) ? self::FAILURE : self::SUCCESS;
    }
}
