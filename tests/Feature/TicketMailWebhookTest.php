<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Plugins\TicketMail\TicketMailSettings;
use Tests\TestCase;

/** Email to ticket by webhook: a mail provider posts the raw email to a secret address and it becomes a ticket. */
class TicketMailWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['gaiacp.ticket_mail.inbound_token' => self::TOKEN, 'gaiacp.support.reply_to' => 'support@gaiacp.test']);
        Client::factory()->create(['email' => 'nikos@example.com']);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('ticket-mail'));
    }

    private function email(string $id = 'one@mail.example.com', array $headers = []): string
    {
        return implode("\r\n", [...$headers, 'From: Nikos <nikos@example.com>', 'To: support@gaiacp.test', 'Subject: Email down', "Message-ID: <{$id}>",
            'MIME-Version: 1.0', 'Content-Type: text/plain; charset=utf-8', '', 'My email does not work.']);
    }

    private function url(string $token = self::TOKEN): string
    {
        return route('webhooks.ticket-mail', $token);
    }

    public function test_mailgun_sendgrid_postmark_and_a_raw_body_all_open_tickets_once(): void
    {
        $this->post($this->url(), ['body-mime' => $this->email('mailgun@x')])->assertOk()->assertJson(['outcome' => 'opened']);
        $this->post($this->url(), ['email' => $this->email('sendgrid@x')])->assertOk();
        $this->postJson($this->url(), ['RawEmail' => $this->email('postmark@x'), 'From' => 'nikos@example.com'])->assertOk();
        $this->call('POST', $this->url(), [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $this->email('raw@x'))->assertOk();

        // The provider posting the same email again
        $this->post($this->url(), ['body-mime' => $this->email('mailgun@x')])->assertOk()->assertJson(['outcome' => 'skipped']);

        $this->assertSame(4, Ticket::query()->count());
        $this->assertSame('My email does not work.', Ticket::query()->first()->messages()->sole()->body);
    }

    public function test_a_from_address_the_mail_server_found_forged_speaks_for_no_client(): void
    {
        config(['gaiacp.ticket_mail.unknown_senders' => 'open']);
        $client = Client::query()->where('email', 'nikos@example.com')->sole();

        // A "pass" the sender wrote themselves, under the receiving server's "fail"
        $forged = $this->email('forged@x', ['Authentication-Results: mx.gaiacp.test; spf=fail smtp.mailfrom=example.com; dmarc=fail header.from=example.com',
            'Authentication-Results: evil.example; dmarc=pass']);
        $this->post($this->url(), ['body-mime' => $forged])->assertOk()->assertJson(['outcome' => 'opened']);
        $this->assertNull(Ticket::query()->sole()->client_id);

        $signed = $this->email('signed@x', ['Authentication-Results: mx.gaiacp.test; spf=fail smtp.mailfrom=example.com; dkim=pass header.d=example.com']);
        $this->post($this->url(), ['body-mime' => $signed])->assertOk();
        $this->assertSame($client->id, Ticket::query()->latest('id')->first()->client_id);
    }

    public function test_a_wrong_token_or_the_plugin_off_finds_nothing(): void
    {
        $this->post($this->url('wrong'), ['body-mime' => $this->email()])->assertNotFound();

        config(['gaiacp.ticket_mail.inbound_token' => null]);
        $this->post($this->url(), ['body-mime' => $this->email()])->assertNotFound();

        config(['gaiacp.ticket_mail.inbound_token' => self::TOKEN]);
        app(PluginManager::class)->disable(app(PluginManager::class)->find('ticket-mail'));
        $this->post($this->url(), ['body-mime' => $this->email()])->assertNotFound();

        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_a_call_without_the_raw_email_is_refused(): void
    {
        $this->postJson($this->url(), ['From' => 'nikos@example.com', 'TextBody' => 'Hi'])->assertStatus(406);
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_the_address_is_made_shown_once_and_turned_off(): void
    {
        config(['gaiacp.ticket_mail.inbound_token' => null]);
        $this->actingAs(User::factory()->create());

        Livewire::test(TicketMailSettings::class)
            ->assertSee('Off: no webhook address yet.')
            ->callAction(TestAction::make('newWebhookAddress')->schemaComponent('webhookActions', 'form'))
            ->assertNotified('Webhook address made');

        $token = (string) config('gaiacp.ticket_mail.inbound_token');
        $this->assertSame(40, strlen($token));
        $this->assertNotSame($token, Setting::query()->find('ticket_mail.inbound_token')->value);

        // Saving the form keeps it, and it is never in the form
        Livewire::test(TicketMailSettings::class)
            ->assertDontSee($token)
            ->assertSet('data.ticket_mail.inbound_token', null)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($token, config('gaiacp.ticket_mail.inbound_token'));

        Livewire::test(TicketMailSettings::class)->callAction(TestAction::make('turnOffWebhook')->schemaComponent('webhookActions', 'form'));
        $this->assertNull(config('gaiacp.ticket_mail.inbound_token'));
    }
}
