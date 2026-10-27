<?php

namespace App\Console\Commands;

use App\Monitoring\ServerMonitoring;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:check-monitors')]
#[Description('Read the status of every server linked to a monitoring service (Uptime Kuma, …); going down or back up is logged and shown in the bell')]
class CheckMonitors extends Command
{
    public function handle(ServerMonitoring $monitoring): int
    {
        $result = $monitoring->check();

        foreach ($result['failed'] as $failure) {
            $this->warn($failure); // logged once as monitoring.failed until it answers again
        }

        $this->info("Checked {$result['servers']} server(s), {$result['changed']} changed status.");

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
