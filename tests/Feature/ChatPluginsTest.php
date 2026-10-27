<?php

namespace Tests\Feature;

use App\Actions\RecordPayment;
use App\Chat\ChatSender;
use App\Models\ActivityLog;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Discord\DiscordSettings;
use Plugins\Slack\SlackSettings;
use Tests\TestCase;

/** Slack and Discord: events posted to a chat channel (App\Chat, plugins/Slack, plugins/Discord). */
class ChatPluginsTest extends TestCase
{
    use RefreshDatabase;

    private const SLACK = 'https://hooks.slack.com/services/T000/B000/xyz';

    private const DISCORD = 'https://discord.com/api/webhooks/123/abc';

    private function enable(string $id): void
    {
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find($id));
    }

    private function pay(): Service
    {
        $service = Service::factory()->create([
            'client_id' => Client::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Papas', 'company' => null]),
            'domain' => 'example.gr',
        ]);
        app(RecordPayment::class)->handle($service, 6000, now(), 'bank');

        return $service;
    }

    public function test_an_event_is_queued_as_a_slack_message_and_posted_by_the_scheduler(): void
    {
        $this->enable('slack');
        config(['gaiacp.slack.webhook_url' => self::SLACK, 'gaiacp.slack.events' => ['payment.recorded']]);
        Http::fake([self::SLACK => Http::response('ok')]);

        $service = $this->pay();
        ActivityLog::record('ticket.opened', 'Not chosen');

        $message = ChatMessage::query()->sole();
        $this->assertSame(['slack', 'payment.recorded'], [$message->channel, $message->event]);
        Http::assertNothingSent();

        $this->artisan('gaiacp:send-chat-messages')->expectsOutput('1 sent, 0 failed.')->assertSuccessful();

        Http::assertSent(function (Request $request) use ($service) {
            $text = $request['blocks'][0]['text']['text'];

            return $request->url() === self::SLACK
                && str_contains($request['text'], 'Payment recorded')
                && str_contains($text, 'Nikos Papas · example.gr · €60.00')
                && str_contains($text, "/admin/services/{$service->id}/edit|Open in GaiaCP>");
        });
        $this->assertSame(ChatMessage::SENT, $message->fresh()->status);
    }

    public function test_discord_gets_an_embed_without_mentions(): void
    {
        $this->enable('discord');
        config(['gaiacp.discord.webhook_url' => self::DISCORD, 'gaiacp.discord.events' => ['server.down']]);
        Http::fake([self::DISCORD => Http::response('', 204)]);

        ActivityLog::record('server.down', 'Server pl1 is down (Uptime Kuma)');
        app(ChatSender::class)->sendDue();

        Http::assertSent(fn (Request $request) => $request['allowed_mentions'] === ['parse' => []]
            && $request['embeds'][0]['title'] === '🔴 Server down'
            && $request['embeds'][0]['description'] === 'Server pl1 is down (Uptime Kuma)'
            && $request['embeds'][0]['color'] === 0xDC2626);
    }

    public function test_nothing_is_queued_while_off_without_a_url_or_after_a_rollback(): void
    {
        config(['gaiacp.slack.webhook_url' => self::SLACK, 'gaiacp.slack.events' => ['payment.recorded']]);
        $this->pay(); // plugin off
        $this->assertSame(0, ChatMessage::query()->count());

        $this->enable('slack');
        try {
            DB::transaction(function () {
                ActivityLog::record('payment.recorded', 'Payment of €60.00 recorded');
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, ChatMessage::query()->count());

        config(['gaiacp.slack.webhook_url' => null]);
        $this->pay();
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_a_failed_message_is_retried_then_logged(): void
    {
        $this->enable('slack');
        config(['gaiacp.slack.webhook_url' => self::SLACK, 'gaiacp.slack.events' => ['server.down']]);
        Http::fake([self::SLACK => Http::response('invalid_token', 403)]);
        $this->travelTo('2026-10-23 10:00:00');
        ActivityLog::record('server.down', 'Server pl1 is down');
        $message = ChatMessage::query()->sole();
        $sender = app(ChatSender::class);

        foreach (['10:00:00', '10:01:00', '10:06:00', '10:36:00'] as $time) {
            $this->travelTo("2026-10-23 {$time}");
            $sender->sendDue();
        }

        $message->refresh();
        $this->assertSame(ChatMessage::FAILED, $message->status);
        $this->assertSame(4, $message->attempts);
        $this->assertSame('HTTP 403: invalid_token', $message->error);
        $this->assertTrue(ActivityLog::query()->where('event', 'chat.failed')->exists());
    }

    public function test_the_settings_page_keeps_the_url_secret_checks_it_and_sends_a_test(): void
    {
        $this->actingAs(User::factory()->create());
        $this->enable('slack');
        Http::fake([self::SLACK => Http::response('ok')]);

        Livewire::test(SlackSettings::class)
            ->set('data.slack.webhook_url', 'https://example.com/hook')
            ->call('save')
            ->assertHasErrors(['data.slack.webhook_url']);

        Livewire::test(SlackSettings::class)
            ->set('data.slack.webhook_url', self::SLACK)
            ->set('data.slack.events', ['order.placed'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(self::SLACK, config('gaiacp.slack.webhook_url'));
        $this->assertSame(['order.placed'], config('gaiacp.slack.events'));
        $this->assertStringNotContainsString(self::SLACK, (string) DB::table('settings')->where('key', 'slack.webhook_url')->value('value'));

        Livewire::test(SlackSettings::class)
            ->assertSet('data.slack.webhook_url', null)
            ->callAction('test')
            ->assertNotified(__('Test message sent'));
        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'Test message from GaiaCP'));

        $this->get(DiscordSettings::getUrl())->assertOk();
    }
}
