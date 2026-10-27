<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Mail\ClientMail;
use App\Mail\RenewalReminderMail;
use App\Models\Client;
use App\Models\Reminder;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The per-service run that is left: Upcoming Domain Renewal Notices. What is owed goes out as payment notices (InvoicesTest). */
class SendRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gaiacp.reminders.before_days' => [14, 7, 1],
            'gaiacp.reminders.overdue_days' => [1, 7],
            'gaiacp.reminders.bcc' => null,
            'gaiacp.invoices.days_before' => 14,
        ]);
        Mail::fake();
    }

    private function run_(string $date): void
    {
        $this->artisan('gaiacp:send-reminders', ['--date' => $date])->assertSuccessful();
    }

    private function domainNotices(): int
    {
        return Mail::sent(RenewalReminderMail::class)->count();
    }

    public function test_sends_the_domain_renewal_notice_on_threshold_day(): void
    {
        $service = Service::factory()->domain()->dueOn('2026-10-22')->create();

        $this->run_('2026-10-08'); // 14 days before

        Mail::assertSent(RenewalReminderMail::class, fn (RenewalReminderMail $m) => $m->hasTo($service->client->email) && $m->template() === MailTemplate::DomainRenewalNotice);
        $this->assertDatabaseHas('reminders', ['service_id' => $service->id, 'kind' => 'before:14']);
    }

    public function test_hosting_gets_no_per_service_reminder(): void
    {
        Service::factory()->dueOn('2026-10-15')->create();
        Service::factory()->dueOn('2026-10-01')->create(); // overdue

        $this->run_('2026-10-08');

        $this->assertSame(0, $this->domainNotices());
        $this->assertSame(0, Reminder::count());
        Mail::assertSent(ClientMail::class, fn (ClientMail $m) => $m->template() === MailTemplate::InvoiceCreated);
    }

    public function test_rerun_on_the_same_day_does_not_resend(): void
    {
        Service::factory()->domain()->dueOn('2026-10-15')->create();

        $this->run_('2026-10-08');
        $this->run_('2026-10-08');

        $this->assertSame(1, $this->domainNotices());
        $this->assertSame(1, Reminder::count());
    }

    public function test_missed_days_send_only_the_most_urgent_notice(): void
    {
        $service = Service::factory()->domain()->dueOn('2026-10-10')->create();

        $this->run_('2026-10-08'); // 2 days left: 14 and 7 both missed

        $this->assertSame(1, $this->domainNotices());
        $this->assertSame(['before:7'], $service->reminders()->pluck('kind')->all());
    }

    public function test_each_threshold_fires_once_as_the_due_date_approaches(): void
    {
        $service = Service::factory()->domain()->dueOn('2026-10-22')->create();

        foreach (['2026-10-08', '2026-10-09', '2026-10-15', '2026-10-16', '2026-10-21', '2026-10-22'] as $day) {
            $this->run_($day);
        }

        $this->assertSame(['before:14', 'before:7', 'before:1'], $service->reminders()->orderBy('id')->pluck('kind')->all());
    }

    public function test_overdue_domains_get_no_per_service_notice(): void
    {
        $service = Service::factory()->domain()->dueOn('2026-10-01')->create();

        $this->run_('2026-10-02');
        $this->run_('2026-10-08');

        $this->assertSame(0, $service->reminders()->count());
    }

    public function test_one_email_per_client_covering_all_their_domains(): void
    {
        $client = Client::factory()->create();
        Service::factory()->for($client)->domain()->dueOn('2026-10-15')->create();
        Service::factory()->for($client)->domain()->dueOn('2026-10-09')->create();
        Service::factory()->domain()->dueOn('2026-10-15')->create(); // another client

        $this->run_('2026-10-08');

        $this->assertSame(2, $this->domainNotices());
        Mail::assertSent(RenewalReminderMail::class, fn (RenewalReminderMail $m) => $m->hasTo($client->email) && count($m->items) === 2);
    }

    public function test_terminated_and_pending_services_are_skipped(): void
    {
        Service::factory()->domain()->dueOn('2026-10-10')->create(['status' => ServiceStatus::Terminated]);
        Service::factory()->domain()->dueOn('2026-10-10')->create(['status' => ServiceStatus::Pending]);

        $this->run_('2026-10-08');

        Mail::assertNothingSent();
    }

    public function test_dry_run_sends_and_records_nothing(): void
    {
        Service::factory()->domain()->dueOn('2026-10-10')->create();

        $this->artisan('gaiacp:send-reminders', ['--date' => '2026-10-08', '--dry-run' => true])
            ->expectsOutputToContain('1 payment notice(s) created, 0 late fee(s) added; 1 emailed new, 0 overdue notice(s); 1 domain renewal email(s)')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, Reminder::count());
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_mail_renders(): void
    {
        config(['gaiacp.payment_instructions' => "IBAN GR00 0000\nBeneficiary: Test"]);
        $service = Service::factory()->domain()->dueOn('2026-10-20')->create(['label' => 'Domain', 'domain' => 'example.gr']);

        $html = (new RenewalReminderMail($service->client, [['service' => $service, 'kind' => 'before:14']], now()->toImmutable()))->render();

        $this->assertStringContainsString('example.gr', $html);
        $this->assertStringContainsString('20/10/2026', $html);
        $this->assertStringContainsString('IBAN GR00 0000', $html);
    }
}
