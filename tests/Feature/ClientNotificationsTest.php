<?php

namespace Tests\Feature;

use App\Actions\Tickets;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Dashboard as ClientDashboard;
use App\Filament\Pages\Dashboard;
use App\Livewire\PanelNotifications;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ClientAlert;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The clients' bell (App\Notifications\ClientAlerts): their own notices, services and tickets, in their language. */
class ClientNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['email' => 'maria@example.com']);
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => 'maria@example.com', 'email_verified_at' => now()]);
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'number' => 'PF-2026-0001', 'client_id' => $this->client->id, 'date' => today(), 'due_date' => today()->addDays(10),
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
    }

    public function test_a_new_payment_notice_from_the_daily_run_reaches_the_client(): void
    {
        $admin = User::factory()->create();
        Service::factory()->for($this->client)->create(['price_cents' => 6000, 'next_due_date' => today()->addDays(10)]);

        $this->artisan('gaiacp:send-reminders')->assertSuccessful();

        $invoice = Invoice::query()->sole();
        $notification = $this->user->notifications()->sole();
        $this->assertSame(ClientAlert::class, $notification->type);
        $this->assertSame("New payment notice {$invoice->number}", $notification->data['title']);
        $this->assertStringEndsWith(', due '.today()->addDays(10)->format('d/m/Y'), $notification->data['body']);
        $this->assertStringEndsWith("/payment-notices/{$invoice->id}", $notification->data['actions'][0]['url']);

        // The admin's own bell is not mixed up with it
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertNotSame(ClientAlert::class, $admin->notifications()->sole()->type);
    }

    public function test_it_is_written_in_the_clients_language(): void
    {
        $this->user->update(['locale' => 'el']);
        $invoice = $this->invoice();

        ActivityLog::record('invoice.paid', 'Payment notice PF-2026-0001 paid', client: $this->client, properties: ['invoice_id' => $invoice->id]);

        $data = $this->user->notifications()->sole()->data;
        $this->assertStringStartsWith('Λάβαμε την πληρωμή σας: ', $data['title']);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_overdue_reminders_and_ticket_answers_notify(): void
    {
        $invoice = $this->invoice();
        ActivityLog::record('invoice.reminder_sent', 'Reminder', client: $this->client, properties: ['invoice_id' => $invoice->id, 'kind' => 'after:3']);
        $this->assertSame('Payment notice PF-2026-0001 is overdue', $this->user->notifications()->sole()->data['title']);
        $this->user->notifications()->delete();

        ActivityLog::record('invoice.reminder_sent', 'Reminder', client: $this->client, properties: ['invoice_id' => $invoice->id, 'kind' => 'before:3']);
        $this->assertStringStartsWith('Payment notice PF-2026-0001 is due on ', $this->user->notifications()->sole()->data['title']);

        $admin = User::factory()->create();
        $ticket = app(Tickets::class)->open($this->client, $admin, 'Email not working', 'Hello');
        $this->user->notifications()->delete();
        app(Tickets::class)->reply($ticket, $admin, 'Fixed it');

        $data = $this->user->notifications()->sole()->data;
        $this->assertSame("New answer to ticket {$ticket->number}", $data['title']);
        $this->assertSame('Email not working', $data['body']);
        $this->assertStringEndsWith("/tickets/{$ticket->id}", $data['actions'][0]['url']);
    }

    public function test_services_and_domains(): void
    {
        $hosting = Service::factory()->for($this->client)->create(['domain' => 'maria.gr', 'status' => ServiceStatus::Active]);
        ActivityLog::record('service.suspended', 'Suspended: overdue', $hosting);
        $data = $this->user->notifications()->sole()->data;
        $this->assertSame('maria.gr was suspended', $data['title']);
        $this->assertStringEndsWith("/services/{$hosting->id}", $data['actions'][0]['url']);
        $this->user->notifications()->delete();

        $domain = Service::factory()->for($this->client)->create(['type' => ServiceType::Domain, 'domain' => 'maria.com', 'status' => ServiceStatus::Active, 'next_due_date' => '2026-12-01']);
        ActivityLog::record('reminder.sent', 'Reminder before:30 sent', $domain);
        $data = $this->user->notifications()->sole()->data;
        $this->assertSame('Domain maria.com renews on 01/12/2026', $data['title']);
        $this->assertStringEndsWith("/domains/{$domain->id}", $data['actions'][0]['url']);
    }

    public function test_nothing_for_their_own_actions_hidden_services_or_with_the_bell_off(): void
    {
        $ticket = app(Tickets::class)->open($this->client, User::factory()->create(), 'Question', 'Hello');
        $this->user->notifications()->delete();
        $this->actingAs($this->user, 'client');
        auth()->shouldUse('client');
        ActivityLog::record('ticket.closed', 'Ticket closed', client: $this->client, properties: ['ticket_id' => $ticket->id]);
        $this->assertSame(0, $this->user->notifications()->count());
        auth()->shouldUse('web');

        $hidden = Service::factory()->for($this->client)->create(['client_visible' => false]);
        ActivityLog::record('service.suspended', 'Suspended', $hidden);
        $this->assertSame(0, $this->user->notifications()->count());

        $this->user->update(['panel_notifications' => false]);
        ActivityLog::record('client.change_approved', 'Approved', client: $this->client);
        $this->assertSame(0, $this->user->notifications()->count());

        $this->user->update(['panel_notifications' => true]);
        config(['gaiacp.client_area.notifications' => false]);
        ActivityLog::record('client.change_approved', 'Approved', client: $this->client);
        $this->assertSame(0, $this->user->notifications()->count());

        // Events about the business, not the client, never reach them
        config(['gaiacp.client_area.notifications' => true]);
        ActivityLog::record('order.client_placed', 'Order', client: $this->client, properties: ['order_id' => 1]);
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_a_swiped_away_notification_is_deleted_and_only_the_clients_own(): void
    {
        config(['gaiacp.client_area.notifications' => true]);
        ActivityLog::record('client.change_approved', 'Approved', client: $this->client);
        $other = User::factory()->create();
        ActivityLog::record('backup.failed', 'Backup failed');
        $mine = $this->user->notifications()->sole();
        $theirs = $other->notifications()->sole();

        // A swipe calls the notification's close(), which tells the bell notificationClosed with its id
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');
        Livewire::test(PanelNotifications::class)
            ->dispatch('notificationClosed', id: $theirs->id)
            ->dispatch('notificationClosed', id: $mine->id)
            ->assertDontSee('Your changes were approved');

        $this->assertSame(0, $this->user->notifications()->count());
        $this->assertSame(1, $other->notifications()->count());
    }

    public function test_the_bell_shows_in_the_client_area_with_only_client_notifications(): void
    {
        $bell = '/class="[^"]*fi-topbar-database-notifications-btn/';
        $this->assertMatchesRegularExpression($bell, $this->actingAs($this->user, 'client')->get(ClientDashboard::getUrl(panel: 'client'))->assertOk()
            ->assertSee('gaiacp-notification-swipe', false) // swiped away on a touch screen (filament.notification-swipe)
            ->getContent());

        // An admin linked to the client: client notifications in the client area, the admin's own in /admin
        $admin = User::factory()->create(['email' => 'linked@example.com']);
        $this->client->user->update(['client_id' => null]);
        $admin->update(['client_id' => $this->client->id]);
        ActivityLog::record('client.change_approved', 'Approved', client: $this->client);
        ActivityLog::record('backup.failed', 'Backup failed');
        $this->assertSame(2, $admin->notifications()->count());

        Filament::setCurrentPanel('client');
        $this->actingAs($admin, 'client');
        Livewire::test(PanelNotifications::class)->assertSee('Your changes were approved')->assertDontSee('Backup failed');

        Filament::setCurrentPanel('admin');
        $this->actingAs($admin, 'web');
        Livewire::test(PanelNotifications::class)->assertSee('Backup failed')->assertDontSee('Your changes were approved');
        $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk();

        // Off in Settings → Client area
        config(['gaiacp.client_area.notifications' => false]);
        $this->assertDoesNotMatchRegularExpression($bell, $this->actingAs($this->user, 'client')->get(ClientDashboard::getUrl(panel: 'client'))->assertOk()->getContent());
    }
}
