<?php

namespace App\Console\Commands;

use App\Credit\AddFunds;
use App\Credit\ApplyCredit;
use App\Invoices\AddDomainLateFees;
use App\Invoices\GenerateInvoices;
use App\Invoices\RenewFreeDomains;
use App\Invoices\SendInvoiceEmails;
use App\Mail\ClientMailer;
use App\Reminders\ExpiringDomainAlerts;
use App\Reminders\ReminderPlanner;
use App\Reminders\SendRenewalReminders;
use App\Support\Currencies;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gaiacp:send-reminders {--date= : Run as if today were this date (Y-m-d)} {--dry-run : Report what would be created and sent without doing it}')]
#[Description('Renew free domains, create the payment notices that are due (with late domain fees), email new and overdue notices and the domain renewal notices, and put domains about to expire in the admins\' bell')]
class SendReminders extends Command
{
    public function handle(RenewFreeDomains $freeDomains, GenerateInvoices $generator, AddDomainLateFees $lateFees, AddFunds $addFunds, ApplyCredit $applyCredit, SendInvoiceEmails $invoiceEmails, ExpiringDomainAlerts $expiring): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $dryRun = (bool) $this->option('dry-run');

        $free = $freeDomains->handle($today, $dryRun);
        $created = count($generator->handle($today, $dryRun));
        $fees = $lateFees->handle($today, $dryRun);
        $staleDeposits = $addFunds->cancelStale($today, $dryRun);
        // Before the emails, so a notice credit paid in full is not emailed as owed.
        $credit = $applyCredit->handle($today, $dryRun);
        $notices = $invoiceEmails->handle($today, $dryRun);
        $domains = (new SendRenewalReminders(ReminderPlanner::fromConfig(), app(ClientMailer::class)))->handle($today, $dryRun);
        $expiringDomains = $expiring->handle($today, $dryRun);

        $this->info(sprintf(
            '%s%d free domain(s) renewed, %d set to end; %d payment notice(s) created, %d late fee(s) added; %d emailed new, %d overdue notice(s); %d domain renewal email(s), %d domain(s) about to expire; %d client(s) paid %s from credit, %d unpaid Add funds notice(s) cancelled; %d failed.',
            $dryRun ? '[dry run] ' : '',
            $free['renewed'],
            $free['ended'],
            $created,
            $fees,
            // A dry run saves nothing, so the notices it would create are counted as emailed too.
            $notices['created'] + ($dryRun ? $created : 0),
            $notices['reminders'],
            $domains['emails'],
            $expiringDomains,
            $credit['clients'],
            Money::format($credit['cents'], Currencies::base()),
            $staleDeposits,
            $notices['failed'] + $domains['failed'],
        ));

        return $notices['failed'] + $domains['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
