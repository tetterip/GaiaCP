<?php

namespace App\Console\Commands;

use App\Actions\Quotes;
use App\Models\Quote;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:expire-quotes {--dry-run : List the quotes that would expire without changing them}')]
#[Description('Mark the sent quotes past their valid until date as Expired (Billing → Quotes)')]
class ExpireQuotes extends Command
{
    public function handle(Quotes $quotes): int
    {
        $due = $quotes->expire((bool) $this->option('dry-run'));
        $due->each(fn (Quote $quote) => $this->line("{$quote->valid_until->toDateString()}: {$quote->number} ({$quote->recipientName()})"));
        $this->info(sprintf('%d %s', $due->count(), $this->option('dry-run') ? 'to expire' : 'expired'));

        return self::SUCCESS;
    }
}
