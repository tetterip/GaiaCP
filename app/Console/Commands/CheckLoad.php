<?php

namespace App\Console\Commands;

use App\Monitoring\ServerLoadCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:check-load')]
#[Description('Read how busy each active server\'s CPU is from its panel, for the dashboard; one that stays busy rings the bell (Settings → Automation → CPU warning)')]
class CheckLoad extends Command
{
    public function handle(ServerLoadCheck $check): int
    {
        $result = $check->check();

        foreach ($result['failed'] as $failure) {
            $this->warn($failure);
        }

        $this->info("Read {$result['read']} of {$result['servers']} server(s).");

        return self::SUCCESS;
    }
}
