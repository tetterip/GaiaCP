<?php

namespace App\Invoices;

use App\Enums\MailTemplate;
use App\Mail\ClientMailer;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceReminder;
use App\Providers\AppServiceProvider;
use App\Reminders\ReminderPlanner;
use App\Support\MailTemplates;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\HtmlString;

/**
 * The emails of unpaid payment notices: Payment Notice Created once, then a reminder on each "days
 * before the due date" of Settings → Reminders that falls after it, and the First and Second overdue
 * notices on the "days after the due date" (the first of those days sends the first notice, every
 * later one the second). Each is recorded only after it was sent, so a
 * failed send is retried on the next run and a re-run never sends the same email twice. A notice gets
 * at most one email a day. A template switched off on the Email templates page is skipped and nothing
 * is recorded, so switching it on resumes.
 */
class SendInvoiceEmails
{
    private ReminderPlanner $planner;

    public function __construct(private ClientMailer $mailer)
    {
        $this->planner = ReminderPlanner::fromConfig();
    }

    /**
     * @param  list<int>|null  $only  these notices only (e.g. the one an order just created)
     * @return array{created: int, reminders: int, failed: int}
     */
    public function handle(CarbonInterface $today, bool $dryRun = false, ?array $only = null): array
    {
        $today = CarbonImmutable::parse($today)->startOfDay();
        $result = ['created' => 0, 'reminders' => 0, 'failed' => 0];

        $invoices = Invoice::query()->unpaid()->when($only !== null, fn ($q) => $q->whereKey($only))
            ->with(['client', 'items', 'reminders'])->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            [$template, $kind] = $this->next($invoice, $today);

            if ($template === null) {
                continue;
            }

            $key = $kind === null ? 'created' : 'reminders';

            if ($dryRun) {
                $result[$key]++;

                continue;
            }

            if (! $this->send($invoice, $template, today: $today)) {
                $result['failed']++;

                continue;
            }

            if ($kind === null) {
                // The day the run is for (--date), so its reminders count from that day.
                $invoice->update(['emailed_at' => $today->isToday() ? now() : $today]);
            } else {
                InvoiceReminder::create(['invoice_id' => $invoice->id, 'kind' => $kind, 'sent_to' => $invoice->client->email, 'sent_at' => now()]);
                ActivityLog::record('invoice.reminder_sent', "Payment notice {$invoice->number}: reminder {$kind} sent to {$invoice->client->email}", client: $invoice->client, properties: ['invoice_id' => $invoice->id, 'kind' => $kind]);
            }

            $result[$key]++;
        }

        return $result;
    }

    /**
     * The email this notice needs today, if any, and its overdue kind (null for "created").
     *
     * @return array{0: MailTemplate|null, 1: string|null}
     */
    public function next(Invoice $invoice, CarbonImmutable $today): array
    {
        $client = $invoice->client;

        if ($invoice->emailed_at === null && $client->renewal_reminders && MailTemplates::enabled(MailTemplate::InvoiceCreated)) {
            return [MailTemplate::InvoiceCreated, null];
        }

        // An "Add funds" notice is the client's own request: no reminders or overdue notices.
        if ($invoice->credit || $invoice->emailed_at?->startOfDay()->equalTo($today)) {
            return [null, null];
        }

        $kind = $this->planner->kindForDueDate($invoice->due_date, $today, $invoice->reminders->pluck('kind')->all());

        if ($kind === null || ! $client->wantsReminder($kind)) {
            return [null, null];
        }

        if (str_starts_with($kind, 'before:')) {
            // A reminder whose day came before the notice was emailed would repeat it.
            $day = $invoice->due_date->subDays((int) substr($kind, strlen('before:')));
            $template = $invoice->emailed_at !== null && $invoice->emailed_at->startOfDay()->lessThan($day) ? MailTemplate::InvoicePaymentReminder : null;
        } else {
            $template = $this->planner->isFirstOverdue($kind) ? MailTemplate::InvoiceFirstOverdue : MailTemplate::InvoiceSecondOverdue;
        }

        return $template !== null && MailTemplates::enabled($template) ? [$template, $kind] : [null, null];
    }

    /** Send one email now; false when it failed (already logged) or the template is switched off. */
    public function send(Invoice $invoice, MailTemplate $template, bool $evenIfOff = false, ?CarbonInterface $today = null): bool
    {
        return $this->mailer->send($template, $invoice->client, fn () => self::values($invoice, $today), evenIfOff: $evenIfOff, invoice: $invoice);
    }

    /** @return array<string, string|HtmlString|null> the invoice_* placeholders */
    public static function values(Invoice $invoice, ?CarbonInterface $today = null): array
    {
        $money = fn (int $cents) => Money::format($cents, $invoice->currency);
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();

        $rows = $invoice->items->map(fn (InvoiceItem $item) => [$item->description, GenerateInvoices::period($item), $money($item->amount_cents)]);

        $tax = $invoice->tax_cents > 0
            ? $invoice->taxLabel().($invoice->tax_inclusive ? ' ('.__('included').')' : '').': '.$money($invoice->tax_cents)
            : (string) $invoice->tax_note;

        return [
            'invoice_number' => $invoice->number,
            'invoice_date' => $invoice->date->format(AppServiceProvider::dateFormat()),
            'invoice_due_date' => $invoice->due_date->format(AppServiceProvider::dateFormat()),
            'invoice_items' => MailTemplates::table([__('Description'), __('Period'), __('Amount')], $rows),
            'invoice_subtotal' => $money($invoice->subtotal_cents),
            'invoice_tax' => $tax,
            'invoice_total' => $money($invoice->total_cents),
            'days_overdue' => (string) max(0, (int) $invoice->due_date->diffInDays($today, false)),
        ];
    }
}
