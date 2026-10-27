<?php

namespace Tests\Feature;

use App\Chat\ChatSender;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Plugins\Telegram\TelegramApi;
use Plugins\Telegram\TelegramSettings;
use Tests\TestCase;

/** The Telegram bot: events to the admins' chat, and answers to /owed, /today, /tickets and /servers from it only. */
class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:ABC-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('telegram'));
        config(['gaiacp.telegram.bot_token' => self::TOKEN, 'gaiacp.telegram.chat_id' => '-1001', 'gaiacp.telegram.events' => ['server.down'], 'gaiacp.telegram.commands' => true]);
    }

    /** Telegram posting a message from $chat to the webhook. */
    private function command(string $text, int $chat = -1001, ?string $secret = null): TestResponse
    {
        return $this->postJson(route('webhooks.telegram'), ['update_id' => 1, 'message' => ['message_id' => 5, 'chat' => ['id' => $chat, 'type' => 'group'], 'text' => $text]],
            ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? TelegramApi::webhookSecret(self::TOKEN)]);
    }

    public function test_an_event_is_sent_to_the_chat_as_html(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        ActivityLog::record('server.down', 'Server <pl1> is down');
        app(ChatSender::class)->sendDue();

        Http::assertSent(fn (Request $request) => $request->url() === TelegramApi::BASE.self::TOKEN.'/sendMessage'
            && $request['chat_id'] === '-1001'
            && $request['parse_mode'] === 'HTML'
            && str_contains($request['text'], '🔴 <b>Server down</b>')
            && str_contains($request['text'], 'Server &lt;pl1&gt; is down'));
    }

    public function test_owed_lists_overdue_notices_by_client(): void
    {
        $this->travelTo('2026-10-24 10:00');
        $nikos = Client::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Papas', 'company' => null]);
        $acme = Client::factory()->create(['company' => 'Acme']);
        $notice = fn (Client $client, int $cents, string $due, InvoiceStatus $status = InvoiceStatus::Unpaid) => Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $client->id, 'status' => $status, 'date' => $due, 'due_date' => $due,
            'currency' => 'EUR', 'subtotal_cents' => $cents, 'tax_cents' => 0, 'total_cents' => $cents,
        ]);
        $notice($nikos, 6000, '2026-10-01');
        $notice($nikos, 4000, '2026-10-10');
        $notice($acme, 2500, '2026-10-20');
        $notice($acme, 9900, '2026-10-30'); // not due yet
        $notice($acme, 9900, '2026-09-01', InvoiceStatus::Paid);

        $this->command('/owed@GaiaBot')->assertOk()
            ->assertJsonPath('method', 'sendMessage')
            ->assertJsonPath('chat_id', '-1001')
            ->assertJsonPath('text', "🧾 <b>Overdue: 3 payment notice(s), €125.00</b>\n1. Nikos Papas – €100.00 (2, since 01/10/2026)\n2. Acme – €25.00 (1, since 20/10/2026)");

        $this->assertTrue(ActivityLog::query()->where('event', 'telegram.command')->exists());
        $this->command('/today')->assertOk()->assertJsonPath('text', fn (string $text) => str_contains($text, 'Today, 24/10/2026'));
        $this->command('/tickets')->assertOk()->assertJsonPath('text', '✅ No ticket is waiting for an answer.');
        $this->command('/whatever')->assertOk()->assertJsonPath('text', fn (string $text) => str_contains($text, '/owed'));
    }

    public function test_other_chats_get_only_their_id_and_a_wrong_secret_nothing(): void
    {
        $this->command('/owed', chat: 42)->assertOk()->assertJsonMissingPath('method');
        $this->command('/start', chat: 42)->assertOk()->assertJsonPath('chat_id', '42')
            ->assertJsonPath('text', fn (string $text) => str_contains($text, 'This chat&#039;s ID is 42.'));

        $this->command('/owed', secret: 'wrong')->assertNotFound();

        config(['gaiacp.telegram.commands' => false]);
        $this->command('/owed')->assertNotFound();
    }

    public function test_settings_find_the_chat_keep_the_token_secret_and_set_the_webhook(): void
    {
        $this->actingAs(User::factory()->create());
        config(['gaiacp.telegram.bot_token' => null, 'gaiacp.telegram.chat_id' => null, 'gaiacp.telegram.commands' => false]);
        Http::fake([
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [['update_id' => 1, 'message' => ['chat' => ['id' => -1002, 'title' => 'Admins']]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        Livewire::test(TelegramSettings::class)
            ->assertSet('data.telegram.bot_token', null)
            ->set('data.telegram.bot_token', self::TOKEN)
            ->callAction(TestAction::make('findChat')->schemaComponent('chatActions', 'form'))
            ->assertNotified('Chat found: Admins')
            ->assertSet('data.telegram.chat_id', '-1002')
            ->set('data.telegram.commands', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame(self::TOKEN, Setting::query()->find('telegram.bot_token')->value);
        $this->assertSame(['-1002', true], [config('gaiacp.telegram.chat_id'), config('gaiacp.telegram.commands')]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === route('webhooks.telegram')
            && $request['secret_token'] === TelegramApi::webhookSecret(self::TOKEN));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setMyCommands') && $request['commands'][0]['command'] === 'owed');

        Livewire::test(TelegramSettings::class)->assertSet('data.telegram.bot_token', null)->set('data.telegram.commands', false)->call('save');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/deleteWebhook'));
        $this->assertSame(self::TOKEN, config('gaiacp.telegram.bot_token'));
    }
}
