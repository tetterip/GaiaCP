<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Emails\EmailResource;
use App\Filament\Client\Resources\Emails\Pages\ListEmails;
use App\Mail\ClientMailer;
use App\Mail\NewClientMail;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Client area: Email history, the emails sent to the client from the email log. */
class ClientEmailHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['email' => 'maria@example.gr']);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->actingAs($user, 'client');
        Filament::setCurrentPanel('client');
    }

    public function test_only_emails_sent_to_the_client_are_marked_for_their_history(): void
    {
        $this->assertTrue(app(ClientMailer::class)->send(MailTemplate::ClientSignup, $this->client, evenIfOff: true));
        Mail::to('admin@example.com')->send(new NewClientMail($this->client));

        [$toClient, $toAdmin] = EmailLog::query()->orderBy('id')->get()->all();
        $this->assertTrue($toClient->to_client);
        $this->assertSame($this->client->id, $toAdmin->client_id);
        $this->assertFalse($toAdmin->to_client);
    }

    public function test_the_client_sees_and_opens_only_their_own_emails(): void
    {
        $mine = $this->log(['subject' => 'Payment notice PF-2026-0001', 'body' => '<html><head></head><body>Total €60.00</body></html>']);
        $this->log(['subject' => 'Not delivered', 'status' => 'failed']);
        $this->log(['subject' => 'New client: Maria', 'to_client' => false]);
        $this->log(['subject' => 'Someone else', 'client_id' => Client::factory()->create()->id]);

        $this->get(EmailResource::getUrl(panel: 'client'))->assertOk();

        Livewire::test(ListEmails::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCountTableRecords(1)
            ->assertActionVisible(TestAction::make('view')->table($mine));

        $message = view('filament.client.email', ['entry' => $mine])->render();
        $this->assertStringContainsString('Total €60.00', $message);
        $this->assertStringContainsString('&lt;base target=&quot;_blank&quot;&gt;', $message);
        $this->assertStringContainsString('maria@example.gr', $message);
        $this->assertStringNotContainsString('copy@example.com', $message);
    }

    public function test_emails_with_a_password_or_sign_in_link_are_listed_without_their_text(): void
    {
        $login = $this->log(['template' => MailTemplate::ClientAreaLogin, 'subject' => 'Your login details', 'body' => '<p>Password: Xy7pQ2mN4vR8</p>']);

        Livewire::test(ListEmails::class)->assertCanSeeTableRecords([$login]);

        $message = view('filament.client.email', ['entry' => $login])->render();
        $this->assertStringNotContainsString('Xy7pQ2mN4vR8', $message);
        $this->assertStringContainsString(e(__('This email had a password or a sign-in link, so its text is not shown here. If you need a new one, use "Forgot password?" on the login page or contact us.')), $message);
    }

    public function test_the_setting_turns_it_off(): void
    {
        config(['gaiacp.client_area.email_history' => false]);

        $this->get(EmailResource::getUrl(panel: 'client'))->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee(__('Email history'));
    }

    private function log(array $attributes): EmailLog
    {
        return EmailLog::create($attributes + [
            'client_id' => $this->client->id,
            'to_client' => true,
            'to' => 'maria@example.gr',
            'bcc' => 'copy@example.com',
            'subject' => 'Hello',
            'body' => '<p>Hello</p>',
            'status' => 'sent',
        ]);
    }
}
