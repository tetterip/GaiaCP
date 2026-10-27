<?php

namespace Tests\Feature;

use App\Actions\SendClientMessages;
use App\Enums\ClientMessageKind;
use App\Enums\ClientStatus;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Dashboard;
use App\Filament\Resources\ClientMessages\Pages\CreateClientMessage;
use App\Filament\Resources\ClientMessages\Pages\EditClientMessage;
use App\Mail\ClientMessageMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** Clients → Messages to clients: filters, paced sending, unsubscribe, client area announcements. */
class ClientMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['gaiacp.locales' => ['el' => 'Ελληνικά', 'en' => 'English'], 'gaiacp.automation.client_messages_per_minute' => 2]);
        $this->server = Server::factory()->create(['name' => 'nl1']);
    }

    private function client(string $first, array $attributes = [], ?Server $server = null, string $domain = 'site.com'): Client
    {
        $client = Client::factory()->create(['first_name' => $first, 'email' => strtolower($first).'@example.com'] + $attributes);
        Service::factory()->for($client)->create(['server_id' => ($server ?? Server::factory()->create())->id, 'domain' => $domain]);

        return $client;
    }

    private function message(array $attributes = []): ClientMessage
    {
        return ClientMessage::create($attributes + [
            'kind' => ClientMessageKind::Info,
            'texts' => [
                'el' => ['subject' => 'Νέα για {$client_first_name}', 'body' => 'Γεια σας {$client_first_name}.'],
                'en' => ['subject' => 'News for {$client_first_name}', 'body' => 'Hello {$client_first_name}.'],
            ],
        ]);
    }

    public function test_filters_pick_the_clients(): void
    {
        $maria = $this->client('Maria', server: $this->server);
        $nikos = $this->client('Nikos', ['locale' => 'en'], domain: 'nikos.gr');
        $optedOut = $this->client('Eleni', ['informational_emails' => false], server: $this->server);
        $this->client('Closed', ['status' => ClientStatus::Closed], server: $this->server);
        $gone = $this->client('Gone');
        $gone->services()->update(['status' => ServiceStatus::Terminated, 'server_id' => $this->server->id]);

        $ids = fn (ClientMessage $m, bool $email = false) => ($email ? $m->emailClients() : $m->clients())->pluck('id')->sort()->values()->all();

        $onServer = $this->message(['filters' => ['servers' => [$this->server->id]]]);
        $this->assertSame([$maria->id, $optedOut->id], $ids($onServer));
        $this->assertSame([$maria->id], $ids($onServer, true), 'informational: not to who opted out');
        $onServer->kind = ClientMessageKind::Service;
        $this->assertSame([$maria->id, $optedOut->id], $ids($onServer, true), 'about their services: everyone');

        $this->assertSame([$nikos->id], $ids($this->message(['filters' => ['tlds' => ['gr']]])));
        $this->assertSame([$nikos->id], $ids($this->message(['filters' => ['locales' => ['en']]])));
        $this->assertNotContains($nikos->id, $ids($this->message(['filters' => ['locales' => ['el']]])));
    }

    public function test_sending_is_paced_once_each_and_in_the_clients_language(): void
    {
        $this->client('Maria', server: $this->server);
        $this->client('Nikos', ['locale' => 'en'], $this->server);
        $this->client('Eleni', server: $this->server);
        $message = $this->message(['filters' => ['servers' => [$this->server->id]]]);

        $this->assertSame(3, app(SendClientMessages::class)->queue($message));
        $this->assertSame(0, app(SendClientMessages::class)->queue($message), 'never twice');

        $this->artisan('gaiacp:send-client-messages')->assertSuccessful();
        Mail::assertSentCount(2);
        $this->assertNull($message->fresh()->finished_at);

        $this->artisan('gaiacp:send-client-messages')->assertSuccessful();
        $this->artisan('gaiacp:send-client-messages')->assertSuccessful();
        Mail::assertSentCount(3);
        $this->assertNotNull($message->fresh()->finished_at);
        $this->assertTrue(ActivityLog::query()->where('event', 'client_message.sent')->exists());

        Mail::assertSent(ClientMessageMail::class, fn (ClientMessageMail $mail) => $mail->client->first_name === 'Nikos'
            && $mail->envelope()->subject === 'News for Nikos'
            && str_contains($mail->render(), 'Hello Nikos.')
            && str_contains($mail->render(), '/unsubscribe/'));
        Mail::assertSent(ClientMessageMail::class, fn (ClientMessageMail $mail) => $mail->client->first_name === 'Maria'
            && $mail->envelope()->subject === 'Νέα για Maria');
    }

    public function test_the_unsubscribe_link_asks_before_turning_emails_off(): void
    {
        $client = $this->client('Maria');
        $url = URL::signedRoute('unsubscribe', ['client' => $client->id]);

        $this->get($url)->assertOk()->assertSee(__('Stop informational emails', [], 'el'));
        $this->assertTrue($client->fresh()->informational_emails, 'opening the link changes nothing');

        $this->post($url)->assertOk();
        $this->assertFalse($client->fresh()->informational_emails);
        $this->get(route('unsubscribe', ['client' => $client->id]))->assertForbidden();
    }

    public function test_the_admin_writes_tests_and_sends_it(): void
    {
        $admin = User::factory()->create(['name' => 'Michalis T', 'email' => 'admin@example.com']);
        $this->actingAs($admin);
        $this->client('Maria', server: $this->server);

        Livewire::test(CreateClientMessage::class)
            ->fillForm([
                'kind' => 'service',
                'texts.el.subject' => 'Μεταφορά server',
                'texts.el.body' => 'Μεταφέρουμε τον λογαριασμό σας.',
                'filters.servers' => [$this->server->id],
                'send_email' => true,
            ])
            ->assertSee(__('Matches :count active client(s); :emails get the email.', ['count' => 1, 'emails' => 1]))
            ->call('create')
            ->assertHasNoFormErrors();

        $message = ClientMessage::query()->sole();
        $this->assertSame(ClientMessageKind::Service, $message->kind);

        Livewire::test(EditClientMessage::class, ['record' => $message->getRouteKey()])
            ->callAction('test', ['locale' => 'el'])
            ->assertNotified(__('Test sent to :email', ['email' => 'admin@example.com']))
            ->callAction('send')
            ->assertNotified(__('Sending to :count client(s)', ['count' => 1]));

        Mail::assertSent(ClientMessageMail::class, fn (ClientMessageMail $mail) => $mail->client->email === 'admin@example.com'
            && $mail->client->first_name === 'Michalis' && ! str_contains($mail->render(), '/unsubscribe/'));
        $this->assertSame(1, $message->recipients()->count());
        $this->assertNotNull($message->fresh()->queued_at);
    }

    public function test_announcements_show_on_home_for_the_clients_they_match(): void
    {
        $maria = $this->client('Maria', server: $this->server);
        $nikos = $this->client('Nikos');
        $this->message(['send_email' => false, 'show_in_client_area' => true, 'show_until' => today()->addDay(), 'filters' => ['servers' => [$this->server->id]]]);
        $this->message(['send_email' => false, 'show_in_client_area' => true, 'show_until' => today()->subDay()]);

        Filament::setCurrentPanel('client');
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $maria->id]), 'client');
        Livewire::test(Dashboard::class)->assertSee('Νέα για Maria')->assertSee('Γεια σας Maria.');

        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $nikos->id]), 'client');
        Livewire::test(Dashboard::class)->assertDontSee('Νέα για');
    }

    public function test_an_announcement_shows_no_html_from_its_text(): void
    {
        $maria = $this->client('Maria');
        $this->message(['send_email' => false, 'show_in_client_area' => true, 'texts' => [
            'el' => ['subject' => 'Νέα', 'body' => "**Σημαντικό**\n\n<img src=x onerror=alert(1)><script>alert(2)</script>"],
        ]]);

        Filament::setCurrentPanel('client');
        $this->actingAs(User::factory()->create(['role' => UserRole::Client, 'client_id' => $maria->id]), 'client');
        $html = Livewire::test(Dashboard::class)->assertSeeHtml('<strong>Σημαντικό</strong>')->html();

        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('alert(2)</script>', $html);
    }
}
