<?php

namespace App\Console\Commands;

use App\Actions\SendClientMessages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:send-client-messages')]
#[Description('Send the next emails of messages to clients (Settings → Automation → Messages per minute; every minute)')]
class SendClientMessageEmails extends Command
{
    public function handle(SendClientMessages $messages): int
    {
        $summary = $messages->sendDue((int) config('gaiacp.automation.client_messages_per_minute', 30));

        if ($summary['sent'] + $summary['failed'] > 0) {
            $this->info("{$summary['sent']} sent, {$summary['failed']} failed.");
        }

        return self::SUCCESS;
    }
}
