<?php

namespace App\Console\Commands;

use App\Chat\ChatSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:send-chat-messages')]
#[Description('Post the queued Slack / Discord messages (chat plugins) and delete old ones')]
class SendChatMessages extends Command
{
    public function handle(ChatSender $sender): int
    {
        $summary = $sender->sendDue();
        $pruned = $sender->prune();

        $this->info("{$summary['sent']} sent, {$summary['failed']} failed".($pruned ? ", {$pruned} old message(s) deleted" : '').'.');

        return self::SUCCESS;
    }
}
