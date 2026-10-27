<?php

namespace Plugins\TicketMail;

use App\Models\ActivityLog;
use App\Plugins\PluginManager;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * gaiacp:fetch-ticket-mail: the unread emails of the support mailbox become tickets or answers (InboundMail), every
 * minute while the plugin is on. Each one is then marked as read (or deleted, Settings → Email to ticket); one that
 * fails is logged and marked too, so it isn't tried every minute. A mailbox it can't reach is logged once until it
 * works again or the error changes.
 */
class FetchTicketMail extends Command
{
    protected $signature = 'gaiacp:fetch-ticket-mail {--limit=50 : Most emails read in one run}';

    protected $description = 'Read the support mailbox: answers join their ticket, new emails open one';

    private const LAST_ERROR = 'ticket_mail.last_error';

    public function handle(PluginManager $plugins, MailboxConnector $connector, InboundMail $inbound): int
    {
        if (! $plugins->enabled('ticket-mail')) {
            $this->info('The Email to ticket plugin is off.');

            return self::SUCCESS;
        }

        if (! $connector->configured()) {
            $this->info('No mailbox set under Settings → Email to ticket.');

            return self::SUCCESS;
        }

        try {
            $mailbox = $connector->mailbox();
            $messages = $connector->folder($mailbox)->messages()
                ->unseen()
                ->withHeaders()
                ->withBody()
                ->withFlags()
                ->limit(max(1, (int) $this->option('limit')))
                ->get();
        } catch (Throwable $e) {
            $this->unreachable($e);

            return self::FAILURE;
        }

        Cache::forget(self::LAST_ERROR);
        $counts = [InboundMail::OPENED => 0, InboundMail::REPLIED => 0, InboundMail::SKIPPED => 0, 'failed' => 0];

        foreach ($messages as $message) {
            /** @var MessageInterface $message */
            try {
                [$outcome, $detail] = $inbound->process($message);
                $counts[$outcome]++;
                $this->line("  {$outcome}: {$detail}");
            } catch (Throwable $e) {
                $counts['failed']++;
                Log::error('Email to ticket failed', ['subject' => $message->subject(), 'error' => $e->getMessage()]);
                ActivityLog::record('ticket_mail.failed', Str::limit("Email from {$message->from()?->email()} could not be added to a ticket: {$e->getMessage()}", 250));
            }

            try {
                config('gaiacp.ticket_mail.after') === 'delete' ? $message->flag('\\Deleted', '+', expunge: true) : $message->markSeen();
            } catch (Throwable $e) {
                Log::warning('Email to ticket could not mark a message', ['error' => $e->getMessage()]);
            }
        }

        rescue(fn () => $mailbox->disconnect(), report: false);

        $this->info("{$counts[InboundMail::OPENED]} opened, {$counts[InboundMail::REPLIED]} replies, {$counts[InboundMail::SKIPPED]} skipped, {$counts['failed']} failed.");

        return self::SUCCESS;
    }

    private function unreachable(Throwable $e): void
    {
        $error = Str::limit($e->getMessage(), 200);
        $this->error("The mailbox could not be read: {$error}");

        if (Cache::get(self::LAST_ERROR) !== $error) {
            Cache::forever(self::LAST_ERROR, $error);
            ActivityLog::record('ticket_mail.unreachable', "The support mailbox could not be read: {$error}");
        }
    }
}
