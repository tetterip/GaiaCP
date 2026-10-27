<?php

namespace App\Reminders;

use App\Enums\DomainRegistrationType;
use App\Enums\MailTemplate;
use App\Enums\ServiceType;
use App\Mail\ClientMailer;
use App\Mail\RenewalReminderMail;
use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\Reminder;
use App\Models\Service;
use App\Support\ClientAreaLinks;
use App\Support\MailTemplates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The daily domain notices: one Upcoming Domain Renewal Notice per client listing
 * every domain that needs one today, and the Expired Domain Notice. Each reminder is recorded only after its email was
 * sent, so a failed send is retried on the next run and a re-run never sends
 * the same reminder twice.
 */
class SendRenewalReminders
{
    public function __construct(private ReminderPlanner $planner, private ClientMailer $mailer) {}

    /**
     * @return array{emails: int, reminders: int, failed: int}
     */
    public function handle(CarbonInterface $today, bool $dryRun = false): array
    {
        $today = CarbonImmutable::parse($today)->startOfDay();

        $services = Service::query()
            ->billable()
            ->where('type', ServiceType::Domain)
            ->where('auto_terminate', false)
            ->whereDate('next_due_date', '<=', $today->addDays($this->planner->horizonDays()))
            ->with(['client', 'reminders'])
            ->get();

        /** @var Collection<int, list<array{service: Service, kind: string}>> $byClient */
        $byClient = collect();

        foreach ($services as $service) {
            $sent = $service->reminders
                ->filter(fn (Reminder $r) => $r->due_date->equalTo($service->next_due_date))
                ->pluck('kind')
                ->all();

            $kind = $this->planner->kindFor($service, $today, $sent);

            // Only the Upcoming Domain Renewal Notice is sent per service; what is owed and overdue goes out
            // as payment notices (App\Invoices\SendInvoiceEmails).
            if ($kind !== null && $service->type === ServiceType::Domain && str_starts_with($kind, 'before:') && $service->client->wantsReminder($kind)) {
                $byClient[$service->client_id] = [...($byClient[$service->client_id] ?? []), ['service' => $service, 'kind' => $kind]];
            }
        }

        $result = ['emails' => 0, 'reminders' => 0, 'failed' => 0];

        foreach ($byClient as $items) {
            $client = $items[0]['service']->client;
            $mail = new RenewalReminderMail($client, $items, $today);
            $mail->clientAreaUrl = ClientAreaLinks::for($client, count($items) === 1 ? $items[0]['service'] : null);

            if (! MailTemplates::enabled($mail->template())) {
                continue; // switched off on the Email templates page; nothing is recorded, so switching it on resumes
            }

            if ($dryRun) {
                $result['emails']++;
                $result['reminders'] += count($items);

                continue;
            }

            try {
                $pending = Mail::to($client->email);
                if ($bcc = config('gaiacp.reminders.bcc')) {
                    $pending->bcc($bcc);
                }
                $pending->send($mail);
            } catch (Throwable $e) {
                rescue(fn () => EmailLog::failed($client->email, $mail->envelope()->subject, $e, $client, $mail->template(), config('gaiacp.reminders.bcc')), report: false);
                Log::error('Renewal reminder failed', ['client_id' => $client->id, 'error' => $e->getMessage()]);
                ActivityLog::record('reminder.failed', "Reminder email to {$client->email} failed: {$e->getMessage()}", client: $client);
                $result['failed']++;

                continue;
            }

            DB::transaction(function () use ($items, $client) {
                foreach ($items as ['service' => $service, 'kind' => $kind]) {
                    Reminder::create([
                        'service_id' => $service->id,
                        'due_date' => $service->next_due_date,
                        'kind' => $kind,
                        'sent_to' => $client->email,
                        'sent_at' => now(),
                    ]);

                    ActivityLog::record('reminder.sent', "Reminder {$kind} sent to {$client->email} for due date {$service->next_due_date->toDateString()}", $service);
                }
            });

            $result['emails']++;
            $result['reminders'] += count($items);
        }

        if (! $dryRun) {
            $this->expiredDomains($today);
        }

        return $result;
    }

    /**
     * The Expired Domain Notice: once per expiry date, for a billable domain whose expiry at the
     * registrar has passed (a renewal moves the expiry, so a renewed domain is never notified).
     */
    private function expiredDomains(CarbonImmutable $today): void
    {
        if (! MailTemplates::enabled(MailTemplate::DomainExpired)) {
            return;
        }

        $services = Service::query()
            ->billable()
            ->where('type', ServiceType::Domain)
            ->whereHas('domainDetails', fn ($q) => $q->whereDate('expires_at', '<', $today)->where('registration_type', '!=', DomainRegistrationType::External))
            ->with(['client', 'domainDetails'])
            ->get();

        foreach ($services as $service) {
            $expires = $service->domainDetails->expires_at;

            if (Reminder::query()->where('service_id', $service->id)->whereDate('due_date', $expires)->where('kind', 'domain_expired')->exists()
                || ! $this->mailer->domain(MailTemplate::DomainExpired, $service)) {
                continue;
            }

            Reminder::create(['service_id' => $service->id, 'due_date' => $expires, 'kind' => 'domain_expired', 'sent_to' => $service->client->email, 'sent_at' => now()]);
        }
    }
}
