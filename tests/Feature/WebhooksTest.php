<?php

namespace Tests\Feature;

use App\Actions\RecordPayment;
use App\Enums\AdminArea;
use App\Events\GaiaEvent;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Plugins\Plugin;
use App\Plugins\PluginManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Webhooks\DeliveryStatus;
use Plugins\Webhooks\WebhookAddress;
use Plugins\Webhooks\WebhookDeliveriesPage;
use Plugins\Webhooks\WebhookDelivery;
use Plugins\Webhooks\WebhookEndpoint;
use Plugins\Webhooks\WebhookSender;
use Plugins\Webhooks\WebhooksPage;
use RuntimeException;
use Tests\TestCase;

/** Core events for plugins (App\Events\GaiaEvent) and the Webhooks plugin that sends them on (Plugins\Webhooks). */
class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://hooks.example.com/gaiacp';

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('webhooks'));
        WebhookAddress::$resolver = fn (string $host) => match ($host) {
            'hooks.example.com' => ['93.184.216.34'],
            'rebound.example.com' => ['93.184.216.34', '10.0.0.5'],
            'localhost' => ['127.0.0.1', '::1'],
            default => [],
        };
    }

    protected function tearDown(): void
    {
        WebhookAddress::$resolver = null;
        parent::tearDown();
    }

    private function endpoint(array $events = ['*'], array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create($attributes + ['name' => 'n8n', 'url' => self::URL, 'secret' => 'whsec_test', 'events' => $events]);
    }

    private function pay(): Service
    {
        $service = Service::factory()->create([
            'client_id' => Client::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Papas', 'email' => 'nikos@example.com']),
            'next_due_date' => '2026-11-01',
            'password' => 'secret-pass',
        ]);
        app(RecordPayment::class)->handle($service, 6000, now(), 'bank', 'REF-1');

        return $service;
    }

    public function test_a_core_action_queues_a_delivery_for_the_endpoints_that_want_it(): void
    {
        $all = $this->endpoint();
        $payments = $this->endpoint(['payment.recorded']);
        $tickets = $this->endpoint(['ticket.opened']);
        $off = $this->endpoint(['*'], ['active' => false]);

        $service = $this->pay();

        $this->assertSame(1, $payments->deliveries()->count());
        $this->assertSame(0, $tickets->deliveries()->count());
        $this->assertSame(0, $off->deliveries()->count());
        $this->assertTrue($all->deliveries()->where('event', 'payment.recorded')->exists());

        $delivery = $payments->deliveries()->sole();
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $payload = json_decode($delivery->payload, true);
        $this->assertSame($delivery->uuid, $payload['uuid']);
        $this->assertSame('payment.recorded', $payload['event']);
        $this->assertSame('nikos@example.com', $payload['client']['email']);
        $this->assertSame($service->id, $payload['service']['id']);
        $this->assertSame(6000, $payload['payment']['amount_cents']);
        $this->assertSame('REF-1', $payload['payment']['reference']);
        $this->assertSame($service->fresh()->next_due_date->format('Y-m-d'), $payload['service']['next_due_date']);
        $this->assertStringNotContainsString('secret-pass', $delivery->payload);
        Http::assertNothingSent(); // sent by the scheduler, not during the action
    }

    public function test_nothing_is_queued_while_the_plugin_is_off_or_when_the_action_rolls_back(): void
    {
        $endpoint = $this->endpoint();

        try {
            DB::transaction(function () {
                ActivityLog::record('payment.recorded', 'Payment of €60.00 recorded');
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, $endpoint->deliveries()->count());

        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('webhooks'));
        $this->pay();
        $this->assertSame(0, $endpoint->deliveries()->count());

        $this->artisan('gaiacp:send-webhooks')->expectsOutput('The Webhooks plugin is off.')->assertSuccessful();
    }

    public function test_events_reach_laravel_listeners_and_plugins_and_a_failing_one_changes_nothing(): void
    {
        Event::fake([GaiaEvent::class]);

        ActivityLog::record('ticket.opened', 'Ticket T-000001 opened: Help');
        ActivityLog::record('client.change_withdrawn', 'Not one of the events');

        Event::assertDispatchedTimes(GaiaEvent::class, 1);
        Event::assertDispatched(GaiaEvent::class, fn (GaiaEvent $event) => $event->name === 'ticket.opened' && $event->payload()['description'] === 'Ticket T-000001 opened: Help');

        $plugin = new class extends Plugin
        {
            public static array $seen = [];

            public function id(): string
            {
                return 'listener-test';
            }

            public function name(): string
            {
                return 'Listener test';
            }

            public function description(): string
            {
                return '';
            }

            public function category(): string
            {
                return 'Integrations';
            }

            public function events(): array
            {
                return ['server.down'];
            }

            public function handleEvent(GaiaEvent $event): void
            {
                self::$seen[] = $event->name;
                throw new RuntimeException('a broken plugin');
            }
        };
        $plugins = app(PluginManager::class);
        $plugins->register($plugin);
        $plugins->enable($plugin);

        $plugins->dispatchEvent(new GaiaEvent('server.down', ActivityLog::record('server.up', 'up')));
        $plugins->dispatchEvent(new GaiaEvent('server.up', ActivityLog::record('server.up', 'up')));

        $this->assertSame(['server.down'], $plugin::$seen);
    }

    public function test_a_delivery_is_signed_and_sent_once_it_is_due(): void
    {
        Http::fake([self::URL => Http::response('ok', 200)]);
        $endpoint = $this->endpoint(['payment.recorded']);
        $this->pay();
        $delivery = $endpoint->deliveries()->sole();

        $this->artisan('gaiacp:send-webhooks')->expectsOutput('1 sent, 0 failed.')->assertSuccessful();

        Http::assertSent(function (Request $request) use ($delivery) {
            $timestamp = $request->header('X-GaiaCP-Timestamp')[0];

            return $request->method() === 'POST'
                && $request->body() === $delivery->payload
                && $request->header('X-GaiaCP-Event')[0] === 'payment.recorded'
                && $request->header('X-GaiaCP-Delivery')[0] === $delivery->uuid
                && $request->header('X-GaiaCP-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$delivery->payload, 'whsec_test');
        });

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertSame(200, $delivery->response_code);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($endpoint->fresh()->last_success_at);

        // Sent ones are not sent again
        $this->artisan('gaiacp:send-webhooks')->expectsOutput('0 sent, 0 failed.');
        Http::assertSentCount(1);
    }

    public function test_a_failed_delivery_is_retried_then_fails_and_the_bell_tells_once(): void
    {
        config(['gaiacp.webhooks.retry_minutes' => [1, 5], 'gaiacp.webhooks.alert_after' => 2]);
        $this->travelTo('2026-10-23 10:00:00');
        $down = true;
        Http::fake(function () use (&$down) {
            return $down ? Http::response('down', 500) : Http::response('ok', 200);
        });
        $endpoint = $this->endpoint(['payment.recorded']);
        $this->pay();
        $delivery = $endpoint->deliveries()->sole();
        $sender = app(WebhookSender::class);

        $sender->sendDue();
        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertSame('HTTP 500', $delivery->error);
        $this->assertSame('2026-10-23 10:01:00', $delivery->next_attempt_at->toDateTimeString());

        $sender->sendDue(); // not due yet
        $this->assertSame(1, $delivery->fresh()->attempts);

        $this->travelTo('2026-10-23 10:01:00');
        $sender->sendDue();
        $this->assertSame('2026-10-23 10:06:00', $delivery->fresh()->next_attempt_at->toDateTimeString());
        $this->assertSame(1, ActivityLog::query()->where('event', 'webhook.failing')->count());

        $this->travelTo('2026-10-23 10:06:00');
        $sender->sendDue();
        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(3, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(3, $endpoint->fresh()->failures);
        $this->assertSame(1, ActivityLog::query()->where('event', 'webhook.failing')->count());

        // A success counts afresh
        $down = false;
        $sender->resend($delivery);
        $this->assertSame(DeliveryStatus::Sent, $delivery->fresh()->status);
        $this->assertSame(0, $endpoint->fresh()->failures);
        $this->assertNull($endpoint->fresh()->alerted_at);
    }

    public function test_an_endpoint_that_keeps_failing_is_switched_off(): void
    {
        config(['gaiacp.webhooks.disable_after' => 2]);
        $admin = User::factory()->create();
        Http::fake([self::URL => Http::failedConnection()]);
        $endpoint = $this->endpoint();
        ActivityLog::record('server.down', 'Server pl1 is down');
        ActivityLog::record('server.up', 'Server pl1 is up again');

        app(WebhookSender::class)->sendDue();

        $this->assertFalse($endpoint->fresh()->active);
        $this->assertTrue(ActivityLog::query()->where('event', 'webhook.disabled')->exists());
        $this->assertContains(__('Webhook :name switched off', ['name' => 'n8n']), $admin->notifications()->get()->pluck('data.title')->all());
        $this->assertSame(0, $endpoint->deliveries()->where('status', DeliveryStatus::Pending)->count());
    }

    public function test_old_deliveries_are_deleted(): void
    {
        Http::fake();
        config(['gaiacp.debug.module_log_days' => 30]);
        $endpoint = $this->endpoint();
        $old = $endpoint->deliveries()->create(['uuid' => 'a', 'event' => 'server.down', 'payload' => '{}', 'status' => DeliveryStatus::Sent]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $recent = $endpoint->deliveries()->create(['uuid' => 'b', 'event' => 'server.down', 'payload' => '{}', 'status' => DeliveryStatus::Sent]);

        $this->artisan('gaiacp:send-webhooks')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_super_admins_add_an_endpoint_and_test_it(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([self::URL => Http::response('', 204)]);

        Livewire::test(WebhooksPage::class)
            ->callAction('create', ['name' => 'Zapier', 'url' => self::URL, 'all_events' => false, 'events' => ['invoice.paid', 'order.placed'], 'active' => true])
            ->assertHasNoActionErrors()
            ->assertNotified(__('Copy the signing secret now'));

        $endpoint = WebhookEndpoint::query()->sole();
        $this->assertSame(['invoice.paid', 'order.placed'], $endpoint->events);
        $this->assertStringStartsWith('whsec_', $endpoint->secret);
        $this->assertStringNotContainsString($endpoint->secret, (string) DB::table('webhook_endpoints')->value('secret'));
        $this->assertArrayNotHasKey('secret', $endpoint->toArray());

        Livewire::test(WebhooksPage::class)
            ->callAction(TestAction::make('test')->table($endpoint))
            ->assertNotified(__('The endpoint answered :code', ['code' => 204]));
        $this->assertSame(WebhookSender::TEST_EVENT, WebhookDelivery::query()->sole()->event);

        $this->get(WebhookDeliveriesPage::getUrl(['endpoint' => $endpoint->id]))->assertOk()->assertSee(WebhookSender::TEST_EVENT);
    }

    public function test_local_and_private_addresses_are_refused_when_saved_and_when_sent(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake();

        foreach (['http://127.0.0.1:8080/x', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/x', 'https://localhost/x',
            'https://rebound.example.com/x', 'http://10.0.0.1/x', 'https://unknown.example.com/x', 'http://[::ffff:127.0.0.1]/x'] as $url) {
            Livewire::test(WebhooksPage::class)
                ->callAction('create', ['name' => 'Inside', 'url' => $url, 'all_events' => true, 'active' => true])
                ->assertHasActionErrors(['url']);
        }
        $this->assertSame(0, WebhookEndpoint::query()->count());

        // Saved before (or the DNS answer changed since): not sent either
        $endpoint = $this->endpoint(attributes: ['url' => 'http://169.254.169.254/latest/meta-data']);
        $delivery = app(WebhookSender::class)->test($endpoint);
        Http::assertNothingSent();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertStringContainsString('local or private network address', (string) $delivery->error);

        config(['gaiacp.webhooks.allow_private_addresses' => true]);
        Livewire::test(WebhooksPage::class)
            ->callAction('create', ['name' => 'n8n here', 'url' => 'http://127.0.0.1:5678/webhook', 'all_events' => true, 'active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(DeliveryStatus::Sent, app(WebhookSender::class)->test($endpoint)->status);
    }

    public function test_only_super_admins_open_the_pages_with_the_switch_on_top(): void
    {
        User::factory()->create();
        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients, AdminArea::Billing, AdminArea::Services, AdminArea::Support])->create());
        $this->get(WebhooksPage::getUrl())->assertForbidden();

        $this->actingAs(User::query()->first());
        $this->get(WebhooksPage::getUrl())->assertOk();

        $this->get(WebhookDeliveriesPage::getUrl())->assertOk();

        $page = Livewire::test(WebhooksPage::class)->assertSet('pluginEnabled', true)->set('pluginEnabled', false);
        $this->assertFalse(app(PluginManager::class)->enabled('webhooks'));
        $page->assertActionHidden('create');
    }
}
