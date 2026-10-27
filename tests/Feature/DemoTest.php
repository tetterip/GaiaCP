<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Actions\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Filament\Clusters\Settings\Pages\CookieSettings;
use App\Filament\Clusters\Settings\Pages\MailSettings;
use App\Filament\Pages\Updates;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Plugins\PluginState;
use App\Provisioning\ProvisionerFactory;
use App\Provisioning\RegistrarFactory;
use App\Support\CookieConsent;
use App\Support\Demo;
use App\Support\DemoSnapshot;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Plugins\Demo\DemoPlugin;
use Plugins\Stripe\StripeSettings;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use RefreshDatabase;

    /** Turns the demo on as GAIACP_DEMO=true would at boot. */
    private function demo(): void
    {
        config(['gaiacp.demo.enabled' => true, 'gaiacp.demo.reset_minutes' => 10]);
        app()->forgetInstance(PluginManager::class);
        Demo::boot();
    }

    public function test_nothing_changes_outside_the_demo(): void
    {
        $this->assertFalse(Demo::enabled());
        $this->assertNull(app(PluginManager::class)->find('demo'));
        $this->assertTrue(PluginInstaller::uploadsAllowed());

        $this->artisan('gaiacp:demo-reset')->assertFailed();
    }

    public function test_the_next_reset_is_on_the_interval_from_the_hour(): void
    {
        $this->demo();

        $this->assertSame('*/10 * * * *', Demo::cron());
        $this->assertSame('10:20', Demo::nextReset(CarbonImmutable::parse('2026-10-25 10:13:05'))->format('H:i'));

        config(['gaiacp.demo.reset_minutes' => 90]);
        $this->assertSame('0 * * * *', Demo::cron());
    }

    public function test_nothing_leaves_the_server(): void
    {
        $this->demo();

        Settings::applyMailer(['smtp' => true, 'host' => 'smtp.example.com']);
        $this->assertSame('log', config('mail.default'));

        $this->expectException(ConnectionException::class);
        Http::get('https://example.com');
    }

    public function test_risky_pages_and_plugins_are_closed(): void
    {
        $this->demo();
        $this->actingAs(User::factory()->create());

        $this->assertFalse(Updates::canAccess());
        $this->assertFalse(MailSettings::canAccess());
        $this->assertFalse(CookieSettings::canAccess());
        config(['gaiacp.cookies.scripts' => '<script>alert(1)</script>']);
        $this->assertNull(CookieConsent::scripts());
        $this->assertFalse(StripeSettings::canAccess());
        $this->assertTrue(CompanySettings::canAccess());
        $this->assertFalse(PluginInstaller::uploadsAllowed());

        $plugins = app(PluginManager::class);
        $this->assertSame(PluginState::OffInDemo, $plugins->state('stripe'));
        $plugins->enable($plugins->find('stripe'));
        $this->assertSame(PluginState::OffInDemo, $plugins->state('stripe'));
        $this->assertSame(PluginState::Enabled, $plugins->state('demo'));
    }

    public function test_a_cart_filled_before_the_last_reset_is_empty_after_it(): void
    {
        $this->demo();
        $marker = dirname(app(DemoSnapshot::class)->path()).'/restored';
        $existed = is_file($marker) ? filemtime($marker) : null;
        File::ensureDirectoryExists(dirname($marker));

        try {
            touch($marker, time() - 30);
            session()->put(ClientOrder::CART, ['old' => ['product' => 1]]);
            session()->put(ClientOrder::CART_AT, time() - 60);
            $this->assertSame([], ClientOrder::cart());
            $this->assertNull(session(ClientOrder::CART));

            // Filled after it: kept
            session()->put(ClientOrder::CART, ['new' => ['product' => 1]]);
            session()->put(ClientOrder::CART_AT, time());
            $this->assertSame(['new'], array_keys(ClientOrder::cart()));
        } finally {
            $existed === null ? File::delete($marker) : touch($marker, $existed);
        }
    }

    public function test_the_demo_accounts_keep_their_login(): void
    {
        $this->demo();
        $admin = User::factory()->create(['email' => Demo::ADMIN_EMAIL, 'password' => Hash::make(Demo::PASSWORD)]);

        $admin->update(['name' => 'Someone', 'email' => 'mine@example.org', 'password' => Hash::make('locked-out')]);
        $admin->refresh();

        $this->assertSame('Someone', $admin->name);
        $this->assertSame(Demo::ADMIN_EMAIL, $admin->email);
        $this->assertTrue(Hash::check(Demo::PASSWORD, $admin->password));

        $admin->delete();
        $this->assertModelExists($admin);
    }

    public function test_the_login_pages_are_filled_in(): void
    {
        $this->demo();

        $this->get('/admin/login')->assertOk()->assertSee(Demo::ADMIN_EMAIL)->assertSee('put back every 10 minutes');
        $this->get('/login')->assertOk()->assertSee(Demo::CLIENT_EMAIL);
    }

    public function test_the_demo_data_works_with_the_demo_panel_and_registrar(): void
    {
        $this->demo();
        $this->seed(DemoSeeder::class);

        $this->assertSame(23, Client::query()->count());
        $this->assertTrue(Invoice::query()->where('status', 'unpaid')->exists());
        $this->assertTrue(User::query()->where('email', Demo::CLIENT_EMAIL)->whereNotNull('client_id')->exists());

        $hosting = Service::query()->where('type', ServiceType::Hosting)->where('status', ServiceStatus::Active)->first();
        $this->assertInstanceOf(DemoPlugin::class, app(PluginManager::class)->serverModule($hosting->server->type));

        app(ServiceLifecycle::class)->suspend($hosting, 'Testing');
        $this->assertSame(ServiceStatus::Suspended, $hosting->fresh()->status);
        app(ServiceLifecycle::class)->unsuspend($hosting->fresh());
        $this->assertSame(ServiceStatus::Active, $hosting->fresh()->status);
        $this->assertSame('Demo panel 1.0', app(ProvisionerFactory::class)->forServer(Server::query()->first())->testConnection());

        $domain = Service::query()->where('type', ServiceType::Domain)->first();
        $registrar = app(RegistrarFactory::class)->forDomain($domain->domain);
        $expiry = $registrar->expiryDate($domain->domain);
        $registrar->renew($domain->domain, 1);
        $this->assertTrue($registrar->expiryDate($domain->domain)->equalTo($expiry->addYear()));
        $this->assertFalse($registrar->available($domain->domain));
        $this->assertTrue($registrar->available('surely-free-name.com'));
    }
}
