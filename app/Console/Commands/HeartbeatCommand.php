<?php

namespace App\Console\Commands;

use App\Support\Health\Heartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:heartbeat {--dry-run : only say what would be sent}')]
#[Description('Ping the monitoring service of Settings → Automation → Ping URL (every 5 minutes)')]
class HeartbeatCommand extends Command
{
    public function handle(Heartbeat $heartbeat): int
    {
        if (! Heartbeat::configured()) {
            $this->line('No Ping URL is set (Settings → Automation): nothing to send.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $plan = $heartbeat->plan();
            $this->line($plan['problems'] ? 'Failing: '.implode('; ', $plan['problems']) : 'All is well.');
            $this->line($plan['url'] ? 'Would ping the '.($plan['problems'] ? 'failure ' : '').'URL.' : 'Would send no ping (no Ping URL on failure is set), so the monitor alerts.');

            return self::SUCCESS;
        }

        $result = $heartbeat->send();

        foreach ($result['problems'] as $problem) {
            $this->warn($problem);
        }

        if ($result['error']) {
            $this->error('The ping failed: '.$result['error']);

            return self::FAILURE;
        }

        $this->info($result['sent'] ? 'Pinged ('.($result['problems'] ? 'failure' : 'OK').').' : 'No ping sent: something failed and no Ping URL on failure is set.');

        return self::SUCCESS;
    }
}
