<?php

namespace Tests\Feature;

use App\Actions\CompleteOrder;
use App\Actions\DomainLifecycle;
use App\Actions\PlaceOrder;
use App\Actions\ServiceLifecycle;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\EditEmailTemplate;
use App\Filament\Pages\EmailTemplates as EmailTemplatesPage;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Mail\ClientMail;
use App\Mail\ClientMailer;
use App\Mail\RenewalReminderMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Reminder;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Reminders\ReminderPlanner;
use App\Reminders\SendRenewalReminders;
use App\Support\MailTemplates;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesEasy;
use Tests\FakesWhm;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    use FakesEasy, FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-09 10:00');
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);
    }

    private function mail(string $kind = 'before:7', array $client = []): RenewalReminderMail
    {
        $service = Service::factory()->dueOn('2026-10-20')->create([
            'client_id' => Client::factory()->create(['first_name' => 'Νίκος', ...$client]),
            'label' => 'Pro hosting', 'domain' => 'example.gr', 'price_cents' => 8000, 'currency' => 'EUR',
        ]);

        return new RenewalReminderMail($service->client, [['service' => $service, 'kind' => $kind]], now()->toImmutable());
    }

    private function switchOn(MailTemplate ...$templates): void
    {
        foreach ($templates as $template) {
            MailTemplates::save($template, null, null, true);
        }
    }

    private function reminders(): SendRenewalReminders
    {
        return new SendRenewalReminders(ReminderPlanner::fromConfig(), app(ClientMailer::class));
    }

    /** @return list<MailTemplate> templates of the ClientMails sent, in order */
    private function sentTemplates(): array
    {
        return Mail::sent(ClientMail::class)->map(fn (ClientMail $m) => $m->template())->values()->all();
    }

    public function test_without_edits_the_built_in_text_is_sent(): void
    {
        config(['gaiacp.company.name' => 'ACME', 'gaiacp.payment_instructions' => 'IBAN GR00 0000']);
        $mail = $this->mail();

        $this->assertSame('Upcoming renewal of your services', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Hello Νίκος,', $html);
        $this->assertStringContainsString('<div class="table"', $html);
        $this->assertStringContainsString('Pro hosting (example.gr)', $html);
        $this->assertStringContainsString('20/10/2026', $html);
        $this->assertStringContainsString('IBAN GR00 0000', $html);
        $this->assertStringContainsString('ACME', $html);
        $this->assertStringNotContainsString('{$', $html);

        $this->assertSame('Overdue: services awaiting payment', $this->mail('overdue:7')->envelope()->subject);
    }

    public function test_an_edited_template_is_used_with_placeholders_filled_and_escaped(): void
    {
        MailTemplates::save(MailTemplate::OverdueNotice, 'Ληξιπρόθεσμη οφειλή {$total} για {$client_name}', "Αγαπητέ/ή {\$client_first_name},\n\n{\$services}\n\nΛήξη: {\$due_date} {\$unknown}");

        $mail = $this->mail('overdue:7', ['first_name' => '<b>Ελένη</b>', 'last_name' => 'Κ.']);

        $this->assertSame('Ληξιπρόθεσμη οφειλή €80.00 για <b>Ελένη</b> Κ.', str_replace(' ', ' ', $mail->envelope()->subject));
        $html = $mail->render();
        $this->assertStringContainsString('Αγαπητέ/ή &lt;b&gt;Ελένη&lt;/b&gt;,', $html);
        $this->assertStringContainsString('Pro hosting (example.gr) — overdue', $html);
        $this->assertStringContainsString('Λήξη: 20/10/2026 {$unknown}', $html);

        $this->assertSame('Upcoming renewal of your services', $this->mail()->envelope()->subject);
    }

    public function test_only_what_differs_from_the_built_in_template_is_stored(): void
    {
        MailTemplates::save(MailTemplate::RenewalReminder, 'Custom', 'Custom body', true);
        $this->assertSame(['subject' => 'Custom', 'body' => 'Custom body', 'enabled' => null], EmailTemplate::sole()->only('subject', 'body', 'enabled'));

        MailTemplates::save(MailTemplate::HostingWelcome, null, null, true);
        $this->assertTrue(MailTemplates::enabled(MailTemplate::HostingWelcome));
        $this->assertFalse(MailTemplates::customized(MailTemplate::HostingWelcome));

        MailTemplates::save(MailTemplate::RenewalReminder, MailTemplate::RenewalReminder->defaultSubject(), MailTemplate::RenewalReminder->defaultBody(), true);
        MailTemplates::save(MailTemplate::HostingWelcome, null, null, false);
        $this->assertSame(0, EmailTemplate::count());
    }

    public function test_every_template_previews_without_leftover_placeholders(): void
    {
        foreach (MailTemplate::cases() as $template) {
            $mail = ClientMail::sample($template);
            $this->assertSame($template, $mail->template(), $template->value);
            $this->assertStringNotContainsString('{$', $mail->envelope()->subject.$mail->render(), $template->value);
        }
    }

    public function test_list_page_shows_every_template_and_the_edit_page_saves(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(SettingsCluster::getUrl())->assertOk()->assertSee(EmailTemplatesPage::getUrl());
        $list = $this->get(EmailTemplatesPage::getUrl())->assertOk();
        foreach (MailTemplate::cases() as $template) {
            $list->assertSee($template->label())->assertSee(EditEmailTemplate::getUrl(['template' => $template->value]));
        }

        $this->get(EditEmailTemplate::getUrl(['template' => 'hosting_welcome']))->assertOk()->assertSee('Hosting Account Welcome Email')->assertSee('{$control_panel_url}', false);
        $this->get('/admin/settings/email-templates/nope')->assertNotFound();

        Livewire::test(EditEmailTemplate::class, ['template' => 'hosting_welcome'])
            ->assertSet('data.enabled', false)
            ->set('data.enabled', true)
            ->set('data.subject', 'Ο λογαριασμός σας {$domain}')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['subject' => 'Ο λογαριασμός σας {$domain}', 'body' => MailTemplate::HostingWelcome->defaultBody(), 'enabled' => true], MailTemplates::get(MailTemplate::HostingWelcome));
    }

    public function test_preview_uses_the_unsaved_text_and_reset_restores_the_built_in_text(): void
    {
        $this->actingAs(User::factory()->create());

        $page = Livewire::test(EditEmailTemplate::class, ['template' => 'overdue_notice'])
            ->set('data.subject', 'Προσοχή {$client_first_name}')
            ->set('data.body', 'Οφειλή {$total}');

        $this->assertSame('Προσοχή Γιώργος', $page->instance()->preview()['subject']);
        $page->mountAction(TestAction::make('preview')->schemaComponent('templateActions', 'form'))
            ->assertMountedActionModalSee('Preview: Προσοχή Γιώργος')
            ->assertMountedActionModalSeeHtml('<iframe sandbox '); // the body's HTML runs no scripts in /admin

        $page->callAction(TestAction::make('reset')->schemaComponent('templateActions', 'form'))
            ->assertSet('data.subject', MailTemplate::OverdueNotice->defaultSubject());
        $this->assertSame(0, EmailTemplate::count());
    }

    public function test_switched_off_emails_are_not_sent(): void
    {
        Mail::fake();
        $service = Service::factory()->dueOn('2026-10-15')->create(['type' => 'maintenance', 'server_id' => null, 'status' => ServiceStatus::Active]);
        MailTemplates::save(MailTemplate::RenewalReminder, null, null, false);

        $this->reminders()->handle(now());
        app(ServiceLifecycle::class)->suspend($service, 'Overdue');

        Mail::assertNothingSent();
        $this->assertSame(0, Reminder::count()); // switching it back on sends the reminder on the next run
    }

    public function test_suspending_and_unsuspending_email_the_client_when_on(): void
    {
        Mail::fake();
        $this->switchOn(MailTemplate::ServiceSuspension, MailTemplate::ServiceUnsuspension);
        $service = Service::factory()->create(['type' => 'maintenance', 'label' => 'Maintenance', 'status' => ServiceStatus::Active]);

        app(ServiceLifecycle::class)->suspend($service, 'Overdue on payment');
        app(ServiceLifecycle::class)->unsuspend($service->refresh());

        $this->assertSame([MailTemplate::ServiceSuspension, MailTemplate::ServiceUnsuspension], $this->sentTemplates());
        Mail::assertSent(ClientMail::class, fn (ClientMail $m) => $m->hasTo($service->client->email) && str_contains($m->render(), 'Overdue on payment'));
        $this->assertSame(2, ActivityLog::where('event', 'email.sent')->count());
    }

    public function test_an_order_sends_its_confirmation_registration_and_hosting_welcome(): void
    {
        Mail::fake();
        $this->fakeWhm();
        $this->fakeEasy();
        $this->switchOn(MailTemplate::OrderConfirmation, MailTemplate::DomainRegistration, MailTemplate::HostingWelcome);
        $client = Client::factory()->create();
        $server = Server::factory()->create(['hostname' => 'cp1.host.gr']);

        $order = app(PlaceOrder::class)->handle($client, [
            ['type' => 'domain', 'label' => 'Domain', 'domain' => 'acme.gr', 'price_cents' => 1800, 'billing_cycle' => BillingCycle::Annually, 'registration_type' => DomainRegistrationType::Register],
            ['type' => 'hosting', 'label' => 'Hosting Basic', 'domain' => '', 'server_id' => $server->id, 'package' => 'basic', 'price_cents' => 6000, 'billing_cycle' => BillingCycle::Annually],
        ]);
        app(CompleteOrder::class)->handle($order);

        $this->assertSame([MailTemplate::OrderConfirmation, MailTemplate::InvoiceCreated, MailTemplate::DomainRegistration, MailTemplate::HostingWelcome], $this->sentTemplates());

        $mails = Mail::sent(ClientMail::class)->values();
        $this->assertStringContainsString('Hosting Basic acme.gr', $mails[0]->render());
        $this->assertStringContainsString('€78.00', $mails[0]->render());
        $this->assertStringContainsString('ns1.host.gr', $mails[2]->render());
        $hosting = $order->services()->where('type', 'hosting')->sole();
        $welcome = $mails[3]->render();
        $this->assertStringContainsString('https://cp1.host.gr:2083', $welcome);
        $this->assertStringContainsString($hosting->username, $welcome);
        $this->assertStringContainsString(e($hosting->password), $welcome);
    }

    public function test_a_completed_transfer_is_announced_once(): void
    {
        Mail::fake();
        $this->fakeEasy();
        $this->switchOn(MailTemplate::DomainTransferCompleted);
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Transfer]);
        $this->easyDomains['acme.gr'] = ['expires' => '2027-03-01', 'ns' => ['ns1.x.gr', 'ns2.x.gr']];

        app(DomainLifecycle::class)->sync($service->refresh());
        app(DomainLifecycle::class)->sync($service->refresh());

        $this->assertSame([MailTemplate::DomainTransferCompleted], $this->sentTemplates());
        $this->assertStringContainsString('01/03/2027', Mail::sent(ClientMail::class)->first()->render());
    }

    public function test_an_expired_domain_is_notified_once(): void
    {
        Mail::fake();
        $this->switchOn(MailTemplate::DomainExpired);
        $service = Service::factory()->domain()->create(['domain' => 'acme.gr', 'next_due_date' => '2026-12-01']);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'expires_at' => '2026-10-08']);
        $fresh = Service::factory()->domain()->create(['domain' => 'fresh.gr', 'next_due_date' => '2026-12-01']);
        $fresh->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, 'expires_at' => '2026-10-09']);

        $this->reminders()->handle(now());
        $this->assertSame([MailTemplate::DomainExpired], $this->sentTemplates());
        Mail::assertSent(ClientMail::class, fn (ClientMail $m) => $m->hasTo($service->client->email));

        // The next day only fresh.gr, which expired yesterday, is new.
        $this->reminders()->handle(now()->addDay());
        Mail::assertSent(ClientMail::class, 2);
        Mail::assertSent(ClientMail::class, fn (ClientMail $m) => $m->hasTo($fresh->client->email));
    }

    public function test_the_signup_email_is_sent_only_when_ticked(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $form = ['first_name' => 'Ελένη', 'last_name' => 'Κ.', 'email' => 'eleni@example.gr', 'phone' => '+30 6912345678', 'country' => 'GR'];

        Livewire::test(CreateClient::class)->assertFormSet(['send_signup_email' => false])->fillForm($form)->call('create')->assertHasNoFormErrors();
        Mail::assertNothingSent();

        Livewire::test(CreateClient::class)->fillForm([...$form, 'email' => 'eleni2@example.gr', 'send_signup_email' => true])->call('create')->assertHasNoFormErrors();
        $this->assertSame([MailTemplate::ClientSignup], $this->sentTemplates());
    }
}
