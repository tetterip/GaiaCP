<?php

namespace Tests\Feature;

use App\Actions\CertificateLifecycle;
use App\Actions\ClientOrder;
use App\Actions\SetUpService;
use App\Certificates\Csr;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\CertificateStatus;
use App\Enums\MailTemplate;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Services\Pages\ViewService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Mail\ClientMail;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Provisioning\ProvisioningException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Plugins\GoGetSsl\GoGetSslSettings;
use Tests\TestCase;

/** SSL certificate services sold through the GoGetSSL plugin: order, validation, issue, reissue / renew, cancel. */
class SslCertificatesTest extends TestCase
{
    use RefreshDatabase;

    private const CRT = "-----BEGIN CERTIFICATE-----\nMIIBcrt\n-----END CERTIFICATE-----";

    private const CA = "-----BEGIN CERTIFICATE-----\nMIIBca\n-----END CERTIFICATE-----";

    private Client $client;

    /** @var array<string, mixed> GoGetSSL's answer to /orders/status/ */
    private array $status = [];

    /** @var list<string> the order ids GoGetSSL handed out */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        config(['gaiacp.gogetssl.username' => 'reseller', 'gaiacp.gogetssl.password' => 'secret', 'gaiacp.gogetssl.dcv_method' => 'dns']);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('gogetssl'));

        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'phone' => '+30 210 1234567', 'country' => 'GR']);
        $this->status = ['order_id' => '900', 'status' => 'processing', 'dcv_method' => 'dns', 'approver_method' => ['dns' => ['record' => '_abc.maria.gr CNAME xyz.sectigo.com']]];
        $this->fakeGoGetSsl();
    }

    private function fakeGoGetSsl(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/auth/') => Http::response(['key' => 'auth-key']),
                str_ends_with($path, '/products/ssl/') => Http::response(['products' => [
                    ['id' => 45, 'name' => 'Sectigo PositiveSSL', 'wildcard' => 'no', 'organization' => 'no', 'periods' => '12,24'],
                    ['id' => 46, 'name' => 'Sectigo PositiveSSL Wildcard', 'wildcard' => 'yes', 'periods' => [12]],
                ]]),
                str_ends_with($path, '/account/balance/') => Http::response(['balance' => '120.50', 'currency' => 'USD']),
                str_contains($path, '/orders/add_ssl_') => Http::response(['order_id' => $this->orders[] = (string) (900 + count($this->orders)), 'success' => true]),
                str_contains($path, '/orders/status/') => Http::response(['order_id' => basename($path)] + $this->status),
                str_contains($path, '/orders/ssl/reissue/') => Http::response(['order_id' => basename($path), 'validation' => ['dns' => ['record' => '_new.maria.gr CNAME new.sectigo.com']]]),
                str_contains($path, '/orders/ssl/change_dcv/') => Http::response(['success' => true]),
                str_contains($path, '/orders/cancel_ssl_order/') => Http::response(['success' => true]),
                default => Http::response(['error' => true, 'message' => 'Unknown', 'description' => $path], 400),
            };
        });
    }

    private function ssl(array $attributes = []): Service
    {
        return Service::factory()->for($this->client)->create([
            'type' => ServiceType::Ssl,
            'server_id' => null,
            'username' => null,
            'package' => 'gogetssl:45',
            'label' => 'PositiveSSL',
            'domain' => 'maria.gr',
            'billing_cycle' => BillingCycle::Annually,
            'next_due_date' => today()->addYear(),
            'status' => ServiceStatus::Pending,
            ...$attributes,
        ]);
    }

    public function test_setting_up_orders_the_certificate_with_a_new_key_and_emails_the_validation(): void
    {
        $service = $this->ssl();

        app(SetUpService::class)->handle($service);

        $certificate = $service->fresh()->certificateDetails;
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame('900', $certificate->remote_id);
        $this->assertSame(CertificateStatus::Pending, $certificate->status);
        $this->assertSame('gogetssl', $certificate->provider);
        $this->assertSame('maria.gr', Csr::commonName($certificate->csr));
        $this->assertStringContainsString('PRIVATE KEY', $certificate->private_key);
        $this->assertArrayNotHasKey('private_key', $certificate->toArray());
        $this->assertSame([['label' => __('Name'), 'value' => '_abc.maria.gr'], ['label' => __('Type'), 'value' => 'CNAME'], ['label' => __('Value'), 'value' => 'xyz.sectigo.com']], CertificateLifecycle::instructions($certificate));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/add_ssl_order/') && str_contains($request->url(), 'auth_key=auth-key')
            && (string) $request['product_id'] === '45' && (string) $request['period'] === '12' && $request['dcv_method'] === 'dns'
            && $request['admin_firstname'] === 'Maria' && $request['admin_phone'] === '+302101234567' && str_contains($request['csr'], 'BEGIN CERTIFICATE REQUEST'));
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::SslValidation
            && str_contains((string) $mail->values['validation'], 'xyz.sectigo.com'));
        $this->assertTrue(ActivityLog::where('event', 'ssl.ordered')->exists());

        // Safe to re-run: nothing is ordered twice.
        app(CertificateLifecycle::class)->order($service->fresh());
        $this->assertSame(['900'], $this->orders);
    }

    public function test_the_hourly_check_finds_it_issued_and_emails_the_client_once(): void
    {
        $service = $this->ssl();
        app(CertificateLifecycle::class)->order($service);
        $this->status = ['status' => 'active', 'crt_code' => self::CRT, 'ca_code' => self::CA, 'valid_till' => today()->addDays(199)->toDateString(), 'end_date' => today()->addYear()->toDateString()];

        $this->artisan('gaiacp:check-certificates')->assertSuccessful();
        $this->artisan('gaiacp:check-certificates')->assertSuccessful();

        $certificate = $service->fresh()->certificateDetails;
        $this->assertSame(CertificateStatus::Issued, $certificate->status);
        $this->assertSame(self::CRT."\n", $certificate->certificate);
        $this->assertTrue($certificate->expires_at->isSameDay(today()->addDays(199)));
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::SslIssued);
        Mail::assertSent(ClientMail::class, 1 + 1); // validation + issued
        $this->assertSame(1, ActivityLog::where('event', 'ssl.issued')->count());
    }

    public function test_a_certificate_ending_inside_a_longer_order_is_reissued_and_an_ending_order_renewed(): void
    {
        $service = $this->ssl(['status' => ServiceStatus::Active, 'next_due_date' => today()->addMonths(13)]);
        $service->certificate()->fill(['provider' => 'gogetssl', 'product_code' => '45', 'remote_id' => '700', 'status' => CertificateStatus::Issued, 'dcv_method' => 'dns',
            'certificate' => self::CRT, 'expires_at' => today()->addDays(20), 'ends_at' => today()->addMonths(6)])->save();

        $this->artisan('gaiacp:check-certificates')->expectsOutputToContain('maria.gr: reissue')->assertSuccessful();

        $certificate = $service->fresh()->certificateDetails;
        $this->assertSame(CertificateStatus::Pending, $certificate->status);
        $this->assertSame('700', $certificate->remote_id);
        $this->assertSame(['dns' => ['record' => '_new.maria.gr CNAME new.sectigo.com']], $certificate->validation);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/ssl/reissue/700'));

        // The order ends within 30 days and the service is paid beyond it: a renewal order.
        $certificate->update(['status' => CertificateStatus::Issued, 'expires_at' => today()->addDays(20), 'ends_at' => today()->addDays(20)]);
        $this->artisan('gaiacp:check-certificates')->expectsOutputToContain('maria.gr: renew')->assertSuccessful();

        $certificate->refresh();
        $this->assertSame('900', $certificate->remote_id);
        $this->assertSame(['700'], $certificate->previous_orders);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/add_ssl_renew_order/'));
    }

    public function test_an_unpaid_service_is_not_renewed(): void
    {
        $service = $this->ssl(['status' => ServiceStatus::Active, 'next_due_date' => today()->addDays(10)]);
        $service->certificate()->fill(['provider' => 'gogetssl', 'product_code' => '45', 'remote_id' => '700', 'status' => CertificateStatus::Issued,
            'certificate' => self::CRT, 'expires_at' => today()->addDays(20), 'ends_at' => today()->addDays(20)])->save();

        $this->artisan('gaiacp:check-certificates')->doesntExpectOutputToContain('maria.gr: renew')->assertSuccessful();

        $this->assertSame('700', $service->fresh()->certificateDetails->remote_id);
    }

    public function test_a_wildcard_is_never_validated_with_a_file_and_a_failure_changes_nothing(): void
    {
        $service = $this->ssl(['package' => 'gogetssl:46']);
        app(CertificateLifecycle::class)->order($service, method: 'http');

        $this->assertSame('*.maria.gr', $service->fresh()->certificateDetails->commonName());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/add_ssl_order/') && $request['dcv_method'] === 'dns');

        $other = $this->ssl(['domain' => 'other.gr', 'billing_cycle' => BillingCycle::Monthly]);

        try {
            app(CertificateLifecycle::class)->order($other);
            $this->fail('A monthly service has no certificate to order.');
        } catch (ProvisioningException) {
        }

        $this->assertSame(ServiceStatus::Pending, $other->fresh()->status);
        $this->assertNull($other->fresh()->certificateDetails->remote_id);
        $this->assertTrue(ActivityLog::where('event', 'provisioning.failed')->exists());
    }

    public function test_the_clients_own_csr_must_be_for_the_domain_and_then_no_key_is_kept(): void
    {
        $service = $this->ssl();

        try {
            app(CertificateLifecycle::class)->order($service, Csr::make('other.gr', [])['csr']);
            $this->fail('A CSR for another domain is refused.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('other.gr', $e->getMessage());
        }

        $this->assertNull($service->fresh()->certificateDetails->remote_id);

        app(CertificateLifecycle::class)->order($service->fresh(), Csr::make('maria.gr', [])['csr']);

        $this->assertSame('900', $service->fresh()->certificateDetails->remote_id);
        $this->assertNull($service->fresh()->certificateDetails->private_key);
    }

    public function test_validation_can_change_to_email_and_the_order_be_cancelled(): void
    {
        $service = $this->ssl();
        $certificates = app(CertificateLifecycle::class);
        $certificates->order($service);

        $certificates->changeValidation($service->fresh(), 'email', 'webmaster@maria.gr');

        $certificate = $service->fresh()->certificateDetails;
        $this->assertSame('email', $certificate->dcv_method);
        $this->assertSame([['label' => __('Approval email to'), 'value' => 'webmaster@maria.gr']], CertificateLifecycle::instructions($certificate));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/ssl/change_dcv/900') && $request['new_method'] === 'email' && $request['approver_email'] === 'webmaster@maria.gr');

        $certificates->cancel($service->fresh(), 'Not needed');

        $this->assertSame(CertificateStatus::Cancelled, $service->fresh()->certificateDetails->status);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/cancel_ssl_order/') && (string) $request['order_id'] === '900');
    }

    public function test_clients_order_an_ssl_product_for_a_domain(): void
    {
        $product = Product::factory()->create(['name' => 'PositiveSSL', 'type' => ServiceType::Ssl, 'package' => 'gogetssl:45', 'auto_setup' => AutoSetup::OnPayment]);

        try {
            app(ClientOrder::class)->items(['product' => $product->id]);
            $this->fail('An SSL certificate needs a domain.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('domain', $e->errors());
        }

        $items = app(ClientOrder::class)->items(['product' => $product->id, 'domain' => 'Maria.gr', 'domain_option' => ClientOrder::REGISTER]);

        $this->assertCount(1, $items); // no domain registration with a certificate
        $this->assertSame('maria.gr', $items[0]['domain']);
        $this->assertSame('gogetssl:45', $items[0]['package']);
    }

    public function test_the_admin_and_client_pages_show_the_certificate(): void
    {
        $service = $this->ssl();
        app(CertificateLifecycle::class)->order($service);
        $this->status = ['status' => 'active', 'crt_code' => self::CRT, 'ca_code' => self::CA, 'valid_till' => today()->addDays(199)->toDateString()];
        app(CertificateLifecycle::class)->refresh($service->fresh());

        $this->actingAs(User::factory()->create());
        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertOk()
            ->assertSee('Sectigo PositiveSSL')
            ->assertActionVisible('downloadCertificate')
            ->assertActionVisible('reissueCertificate')
            ->assertActionHidden('orderCertificate');

        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');

        Livewire::test(ViewService::class, ['record' => $service->getRouteKey()])
            ->assertOk()
            ->assertSee(__('SSL certificate'))
            ->assertSee(__('Issued'))
            ->assertDontSee('gogetssl')
            ->assertActionVisible('downloadCertificate')
            ->assertActionHidden('changeValidation');
    }

    public function test_the_settings_page_tests_the_connection(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(GoGetSslSettings::class)
            ->assertOk()
            ->callAction('testConnection')
            ->assertNotified();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://my.gogetssl.com/api/account/balance/'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'sandbox'));
    }
}
