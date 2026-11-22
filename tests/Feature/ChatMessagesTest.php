<?php

namespace Tests\Feature;

use App\Actions\RecordPayment;
use App\Chat\ChatSender;
use App\Models\ActivityLog;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\Service;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestChat\TestChatPlugin;
use Tests\TestCase;

/** Events queued for a chat plugin and posted by the scheduler (App\Chat; the Slack, Discord and Telegram plugins use it). */
class ChatMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = TestChatPlugin::URL;

    private TestChatPlugin $chat;

    protected function setUp(): void
    {
        parent::setUp();
        app(PluginManager::class)->register($this->chat = new TestChatPlugin);
    }

    private function enable(): void
    {
        app(PluginManager::class)->enable($this->chat);
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

    public function test_a_chosen_event_is_queued_and_posted_by_the_scheduler(): void
    {
        $this->enable();
        config(['gaiacp.test-chat.webhook_url' => self::URL, 'gaiacp.test-chat.events' => ['payment.recorded']]);
        Http::fake([self::URL => Http::response('ok')]);

        $service = $this->pay();
        ActivityLog::record('ticket.opened', 'Not chosen');

        $message = ChatMessage::query()->sole();
        $this->assertSame(['test-chat', 'payment.recorded'], [$message->channel, $message->event]);
        Http::assertNothingSent();

        $this->artisan('gaiacp:send-chat-messages')->expectsOutput('1 sent, 0 failed.')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && str_contains($request['text'], 'Payment recorded')
            && str_contains($request['text'], 'Nikos Papas · example.gr · €60.00')
            && str_ends_with($request['url'], "/admin/services/{$service->id}/edit"));
        $this->assertSame(ChatMessage::SENT, $message->fresh()->status);
    }

    public function test_nothing_is_queued_while_off_without_a_url_or_after_a_rollback(): void
    {
        config(['gaiacp.test-chat.webhook_url' => self::URL, 'gaiacp.test-chat.events' => ['payment.recorded']]);
        $this->pay(); // plugin off
        $this->assertSame(0, ChatMessage::query()->count());

        $this->enable();
        try {
            DB::transaction(function () {
                ActivityLog::record('payment.recorded', 'Payment of €60.00 recorded');
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, ChatMessage::query()->count());

        config(['gaiacp.test-chat.webhook_url' => null]);
        $this->pay();
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_a_failed_message_is_retried_then_logged(): void
    {
        $this->enable();
        config(['gaiacp.test-chat.webhook_url' => self::URL, 'gaiacp.test-chat.events' => ['server.down']]);
        Http::fake([self::URL => Http::response('invalid_token', 403)]);
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
}
