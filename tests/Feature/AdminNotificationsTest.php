<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Enums\UserRole;
use App\Filament\Pages\Dashboard;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Reminders\ExpiringDomainAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_order_reaches_admins_who_open_clients_in_their_language(): void
    {
        $super = User::factory()->create();
        $clients = User::factory()->limitedTo([AdminArea::Clients])->create(['locale' => 'el']);
        $billing = User::factory()->limitedTo([AdminArea::Billing])->create();
        $off = User::factory()->create(['panel_notifications' => false]);
        $inactive = User::factory()->create(['active' => false]);
        $client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papadopoulou']);

        ActivityLog::record('order.client_placed', 'Order #7 placed in the client area', client: $client, properties: ['order_id' => 7]);

        $this->assertSame(1, $super->notifications()->count());
        $this->assertSame(1, $clients->notifications()->count());
        $this->assertSame(0, $billing->notifications()->count());
        $this->assertSame(0, $off->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());

        $data = $super->notifications()->first()->data;
        $this->assertSame('New order #7', $data['title']);
        $this->assertSame('Maria Papadopoulou placed an order in the client area', $data['body']);
        $this->assertStringEndsWith('/admin/orders/7', $data['actions'][0]['url']);
        $this->assertSame('Νέα παραγγελία #7', $clients->notifications()->first()->data['title']);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_admin_who_did_it_is_not_told(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($me);

        ActivityLog::record('provisioning.failed', 'account.suspended failed: timeout', Service::factory()->create(['domain' => 'example.com']));

        $this->assertSame(0, $me->notifications()->count());
        $this->assertSame('Panel or registrar call failed: example.com', $other->notifications()->first()->data['title']);
    }

    public function test_payments_show_only_when_no_admin_recorded_them(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        $invoice = Invoice::create([
            'number' => 'PF-2026-0001', 'client_id' => Client::factory()->create()->id, 'date' => today(), 'due_date' => today(),
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);

        $this->actingAs($admin);
        ActivityLog::record('invoice.paid', 'Payment notice PF-2026-0001 paid', client: $invoice->client, properties: ['invoice_id' => $invoice->id]);
        $this->assertSame(0, $other->notifications()->count());

        // The Stripe webhook: nobody signed in
        auth()->logout();
        ActivityLog::record('invoice.paid', 'Payment notice PF-2026-0001 paid', client: $invoice->client, properties: ['invoice_id' => $invoice->id]);
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringStartsWith('Payment received: ', $admin->notifications()->first()->data['title']);
        $this->assertStringContainsString('60', $admin->notifications()->first()->data['title']);
    }

    public function test_new_payment_notices_from_the_daily_run_reach_billing_admins(): void
    {
        $billing = User::factory()->limitedTo([AdminArea::Billing])->create();
        $clients = User::factory()->limitedTo([AdminArea::Clients])->create();
        $client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papadopoulou']);
        Service::factory()->for($client)->create(['price_cents' => 6000, 'next_due_date' => today()->addDays(10)]);

        $this->artisan('gaiacp:send-reminders')->assertSuccessful();

        $invoice = Invoice::query()->sole();
        $this->assertSame(0, $clients->notifications()->count());
        $data = $billing->notifications()->sole()->data;
        $this->assertSame("New payment notice {$invoice->number}", $data['title']);
        $this->assertStringStartsWith('Maria Papadopoulou, ', $data['body']);
        $this->assertStringEndsWith(', due '.today()->addDays(10)->format('d/m/Y'), $data['body']);
        $this->assertStringEndsWith("/admin/invoices/{$invoice->id}", $data['actions'][0]['url']);
    }

    public function test_an_orders_own_payment_notice_makes_no_notification(): void
    {
        $admin = User::factory()->create();
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'number' => 'PF-2026-0001', 'client_id' => $client->id, 'order_id' => Order::create(['client_id' => $client->id, 'status' => 'pending'])->id,
            'date' => today(), 'due_date' => today(), 'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);

        ActivityLog::record('invoice.created', 'Payment notice PF-2026-0001', client: $invoice->client, properties: ['invoice_id' => $invoice->id]);

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_server_and_system_failures_go_to_super_admins_only(): void
    {
        $super = User::factory()->create();
        $limited = User::factory()->limitedTo(AdminArea::cases())->create();

        ActivityLog::record('backup.failed', 'Backup failed: disk full');
        ActivityLog::record('provisioning.failed', 'server.panel_login failed on nl1: timeout', properties: ['event' => 'server.panel_login']);

        $this->assertSame(2, $super->notifications()->count());
        $this->assertSame(0, $limited->notifications()->count());
    }

    public function test_other_events_make_no_notification(): void
    {
        $admin = User::factory()->create();

        ActivityLog::record('service.suspended', 'Suspended', Service::factory()->create());
        ActivityLog::record('email.sent', 'Sent');

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_domains_about_to_expire_are_told_once_per_expiry_date(): void
    {
        $admin = User::factory()->create();
        $today = CarbonImmutable::parse('2026-10-20');
        $soon = Service::factory()->domain()->create(['domain' => 'soon.gr']);
        $soon->registration()->update(['expires_at' => '2026-10-25']);
        $later = Service::factory()->domain()->create(['domain' => 'later.gr']);
        $later->registration()->update(['expires_at' => '2026-12-25']);
        $tracked = Service::factory()->domain()->create(['domain' => 'tracked.gr']);
        $tracked->registration()->update(['expires_at' => '2026-10-25', 'registration_type' => 'external']);
        $ending = Service::factory()->domain()->create(['domain' => 'ending.gr', 'auto_terminate' => true]);
        $ending->registration()->update(['expires_at' => '2026-10-25']);

        $this->assertSame(1, app(ExpiringDomainAlerts::class)->handle($today, dryRun: true));
        $this->assertSame(0, $admin->notifications()->count());

        $this->assertSame(1, app(ExpiringDomainAlerts::class)->handle($today));
        $this->assertSame(0, app(ExpiringDomainAlerts::class)->handle($today->addDay()));

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('Domain soon.gr expires on 25/10/2026', $admin->notifications()->first()->data['title']);
    }

    public function test_the_bell_shows_in_the_top_bar_unless_the_admin_turned_it_off(): void
    {
        // The theme's stylesheet names the class too, so look for it on an element
        $bell = '/class="[^"]*fi-topbar-database-notifications-btn/';
        $admin = User::factory()->create();
        $this->assertMatchesRegularExpression($bell, $this->actingAs($admin)->get(Dashboard::getUrl())->assertOk()
            ->assertSee('gaiacp-bell-seen', false) // rings when unread ones appear (filament.bell-ring)
            ->assertSee('gaiacp-notification-swipe', false) // swiped away on a touch screen (filament.notification-swipe)
            ->getContent());

        $admin->update(['panel_notifications' => false]);
        $this->assertDoesNotMatchRegularExpression($bell, $this->actingAs($admin->fresh())->get(Dashboard::getUrl())->assertOk()->getContent());
    }

    public function test_client_users_never_get_notifications(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'super_admin' => false]);

        ActivityLog::record('client.signed_up', 'Signed up', client: $client);

        $this->assertSame(0, $user->notifications()->count());
    }
}
