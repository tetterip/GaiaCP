<?php

namespace App\Console\Commands;

use App\Actions\Tickets;
use App\Models\Ticket;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:close-tickets {--dry-run : List the tickets that would close without closing them}')]
#[Description('Close the support tickets that waited on the client longer than Settings → Support → Close tickets after, emailing Support Ticket Closed')]
class CloseTickets extends Command
{
    public function handle(Tickets $tickets): int
    {
        if (blank(config('gaiacp.support.auto_close_days'))) {
            $this->info('Closing tickets by itself is off (Settings → Support).');

            return self::SUCCESS;
        }

        $closed = $tickets->closeStale((bool) $this->option('dry-run'));
        $closed->each(fn (Ticket $ticket) => $this->line("{$ticket->number}: {$ticket->subject}"));
        $this->info(sprintf('%d %s', $closed->count(), $this->option('dry-run') ? 'to close' : 'closed'));

        return self::SUCCESS;
    }
}
