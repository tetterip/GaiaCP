<?php

namespace App\Console\Commands;

use App\Actions\Leads;
use App\Models\Lead;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:lead-follow-ups {--dry-run : List the leads the bell would ring for without ringing it}')]
#[Description('Ring the bell for the open leads whose next contact day has come (Clients → Leads), once per contact day')]
class LeadFollowUps extends Command
{
    public function handle(Leads $leads): int
    {
        $due = $leads->followUps((bool) $this->option('dry-run'));
        $due->each(fn (Lead $lead) => $this->line("{$lead->next_contact_on->toDateString()}: {$lead->displayName()}"));
        $this->info(sprintf('%d %s', $due->count(), $this->option('dry-run') ? 'to remind' : 'reminded'));

        return self::SUCCESS;
    }
}
