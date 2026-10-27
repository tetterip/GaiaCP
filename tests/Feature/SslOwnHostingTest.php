<?php

namespace Tests\Feature;

use App\Actions\CertificateLifecycle;
use App\Actions\SetUpService;
use App\Certificates\CertificateHosting;
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
use App\Models\Certificate;
use App\Models\Client;
use App\Models\ModuleLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Provisioning\ProvisioningException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\FakesPlesk;
use Tests\FakesWhm;
use Tests\TestCase;

/** An SSL certificate for a domain the client hosts on our own cPanel or Plesk server: validated and installed there. */
class SslOwnHostingTest extends TestCase
{
    use FakesPlesk, FakesWhm, RefreshDatabase;

    private const CRT = "-----BEGIN CERTIFICATE-----\nMIIBcrt\n-----END CERTIFICATE-----";

    private const CA = "-----BEGIN CERTIFICATE-----\nMIIBca\n-----END CERTIFICATE-----";

    private const FILE = '4F3A9C.txt';

    private Client $client;

    /** @var array<string, mixed> GoGetSSL's answer to /orders/status/ */
    private array $status = [];

    /** The method GoGetSSL says the order uses: the one it was ordered with. */
    private string $method = 'email';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        config(['gaiacp.gogetssl.username' => 'reseller', 'gaiacp.gogetssl.password' => 'secret', 'gaiacp.gogetssl.dcv_method' => 'email']);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('gogetssl'));

        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'country' => 'GR']);
        $this->status = ['order_id' => '900', 'status' => 'processing', 'approver_method' => [
            'dns' => ['record' => '_abc.maria.gr CNAME xyz.sectigo.com'],
            'http' => ['link' => 'http://maria.gr/.well-known/pki-validation/'.self::FILE, 'filename' => self::FILE, 'content' => "hash\nsectigo.com\ntoken"],
        ]];

        // GoGetSSL first; everything else is left to the WHM or Plesk fake.
        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'gogetssl.com')) {
                return null;
            }

            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/auth/') => Http::response(['key' => 'auth-key']),
                str_ends_with($path, '/products/ssl/') => Http::response(['products' => [
                    ['id' => 45, 'name' => 'Sectigo PositiveSSL', 'wildcard' => 'no', 'periods' => '12'],
                    ['id' => 46, 'name' => 'Sectigo PositiveSSL Wildcard', 'wildcard' => 'yes', 'periods' => [12]],
                ]]),
                str_contains($path, '/orders/add_ssl_order/') => Http::response(['order_id' => '900', 'success' => true]),
                str_contains($path, '/orders/status/') => Http::response(['dcv_method' => $this->method] + $this->status),
                str_contains($path, '/orders/ssl/revalidate/') => Http::response(['success' => true]),
                default => Http::response(['error' => true, 'message' => 'Unknown', 'description' => $path], 400),
            };
        });
    }

    private function hosting(Server $server, array $attributes = []): Service
    {
        return Service::factory()->for($this->client)->create([
            'type' => ServiceType::Hosting,
            'server_id' => $server->id,
            'username' => 'maria',
            'package' => 'basic',
            'domain' => 'maria.gr',
            'status' => ServiceStatus::Active,
            ...$attributes,
        ]);
    }

    private function ssl(array $attributes = []): Service
    {
        return Service::factory()->for($this->client)->create([
            'type' => ServiceType::Ssl,
            'server_id' => null,
            'username' => null,
            'package' => 'gogetssl:45',
            'domain' => 'maria.gr',
            'billing_cycle' => BillingCycle::Annually,
            'next_due_date' => today()->addYear(),
            'status' => ServiceStatus::Pending,
            ...$attributes,
        ]);
    }

    private function issue(): void
    {
        $this->status = ['status' => 'active', 'crt_code' => self::CRT, 'ca_code' => self::CA, 'valid_till' => today()->addDays(199)->toDateString(), 'end_date' => today()->addYear()->toDateString()];
    }

    public function test_on_cpanel_the_validation_file_is_saved_in_the_website_and_the_issued_certificate_installed(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $hosting = $this->hosting(Server::factory()->create());
        $service = $this->ssl();
        $this->method = 'http';
        config(['gaiacp.client_area.notifications' => true]);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email_verified_at' => now()]);

        app(SetUpService::class)->handle($service);

        // A file is something cPanel can put in place, so it is picked over the provider's default (email).
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/add_ssl_order/') && $request['dcv_method'] === 'http');
        $this->assertSame("hash\nsectigo.com\ntoken", $this->whmFiles['/home/maria/public_html/.well-known/pki-validation/'.self::FILE] ?? null);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/ssl/revalidate/900'));

        $certificate = $service->fresh()->certificateDetails;
        $this->assertSame($hosting->id, $certificate->hosting_service_id);
        $this->assertNotNull($certificate->validation_placed_at);
        $this->assertTrue(ActivityLog::where('event', 'ssl.validation_placed')->exists());
        Mail::assertNotSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::SslValidation);

        $this->issue();
        $this->artisan('gaiacp:check-certificates')->assertSuccessful();

        $installed = $this->whmCertificates['maria.gr'] ?? null;
        $this->assertSame('maria', $installed['user']);
        $this->assertSame(self::CRT."\n", $installed['cert']);
        $this->assertSame($certificate->fresh()->private_key, $installed['key']);
        $this->assertSame(self::CA."\n", $installed['cabundle']);
        $this->assertNotNull($certificate->fresh()->installed_at);
        $this->assertSame(1, ActivityLog::where('event', 'ssl.installed')->count());

        // The client's bell: nothing to validate, then the certificate is on their website.
        $this->assertEqualsCanonicalizing(['SSL certificate ready: maria.gr', 'SSL certificate installed: maria.gr'], $user->notifications()->get()->pluck('data.title')->all());

        // The private key never reaches the module log.
        $this->assertFalse(ModuleLog::where('request', 'like', '%PRIVATE KEY%')->exists());

        // Read again: issued already, nothing installed twice.
        $this->artisan('gaiacp:check-certificates')->assertSuccessful();
        $this->assertSame(1, ActivityLog::where('event', 'ssl.installed')->count());
    }

    public function test_on_plesk_a_dns_record_is_added_once_and_the_certificate_is_installed_and_used(): void
    {
        $this->fakePlesk();
        $this->pleskWebspaces['maria.gr'] = ['owner' => 10, 'status' => 0, 'plan' => 'Starter', 'ftp_login' => 'maria', 'ip' => '203.0.113.5'];
        $this->hosting(Server::factory()->plesk()->create());
        $service = $this->ssl();
        $this->method = 'dns';

        app(SetUpService::class)->handle($service);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/add_ssl_order/') && $request['dcv_method'] === 'dns');
        $this->assertSame([['site_id' => 1, 'type' => 'CNAME', 'host' => '_abc.maria.gr.', 'value' => 'xyz.sectigo.com.']], $this->pleskDnsRecords);

        // Safe to re-run: the record is there already.
        $this->assertTrue(app(CertificateHosting::class)->placeValidation($service->fresh()));
        $this->assertCount(1, $this->pleskDnsRecords);

        $this->issue();
        app(CertificateLifecycle::class)->refresh($service->fresh());

        $this->assertCount(1, $this->pleskCertificates);
        $name = array_key_first($this->pleskCertificates);
        $this->assertStringStartsWith('GaiaCP maria.gr ', $name);
        $this->assertSame('maria.gr', $this->pleskCertificates[$name]['webspace']);
        $this->assertSame($name, $this->pleskWebspaces['maria.gr']['certificate_name'] ?? null);
        $this->assertSame('true', $this->pleskWebspaces['maria.gr']['ssl'] ?? null);

        // SSL → Install on hosting again: the same certificate is found and used.
        app(CertificateLifecycle::class)->install($service->fresh());
        $this->assertCount(1, $this->pleskCertificates);
    }

    public function test_another_clients_hosting_or_the_setting_off_leaves_validation_to_the_client(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->hosting(Server::factory()->create(), ['client_id' => Client::factory()->create()->id]);
        $service = $this->ssl();

        app(SetUpService::class)->handle($service);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/add_ssl_order/') && $request['dcv_method'] === 'email');
        $this->assertSame([], $this->whmFiles);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::SslValidation);

        config(['gaiacp.domains.ssl_own_hosting' => false]);
        $this->hosting(Server::factory()->create(), ['username' => 'maria2']);
        $this->assertNull(app(CertificateHosting::class)->find($service->fresh()));
    }

    public function test_a_failed_install_keeps_the_certificate_issued_and_the_admin_can_try_again(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->whmFailures['SSL::install_ssl'] = 'The certificate does not match the key.';
        $this->hosting(Server::factory()->create());
        $service = $this->ssl();
        $this->method = 'http';
        app(SetUpService::class)->handle($service);

        $this->issue();
        app(CertificateLifecycle::class)->refresh($service->fresh());

        $certificate = $service->fresh()->certificateDetails;
        $this->assertSame(CertificateStatus::Issued, $certificate->status);
        $this->assertNull($certificate->installed_at);
        $this->assertStringContainsString('does not match', $certificate->error);
        $this->assertTrue(ActivityLog::where('event', 'provisioning.failed')->where('description', 'like', 'ssl.installed failed%')->exists());

        try {
            app(CertificateLifecycle::class)->install($service->fresh());
            $this->fail('The panel said no.');
        } catch (ProvisioningException) {
        }

        unset($this->whmFailures['SSL::install_ssl']);
        app(CertificateLifecycle::class)->install($service->fresh());
        $this->assertNotNull($service->fresh()->certificateDetails->installed_at);
        $this->assertNull($service->fresh()->certificateDetails->error);
    }

    public function test_a_wildcard_on_cpanel_is_validated_by_the_client_but_still_installed(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->hosting(Server::factory()->create());
        $service = $this->ssl(['package' => 'gogetssl:46']);
        $this->method = 'dns';

        app(SetUpService::class)->handle($service);

        $this->assertSame([], $this->whmFiles);
        Mail::assertSent(ClientMail::class, fn (ClientMail $mail) => $mail->mailTemplate === MailTemplate::SslValidation);

        $this->issue();
        app(CertificateLifecycle::class)->refresh($service->fresh());
        $this->assertArrayHasKey('maria.gr', $this->whmCertificates);
    }

    public function test_the_clients_own_csr_means_no_key_to_install(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->hosting(Server::factory()->create());
        $service = $this->ssl(['status' => ServiceStatus::Active]);
        $service->certificate()->fill(['provider' => 'gogetssl', 'product_code' => '45', 'remote_id' => '900', 'status' => CertificateStatus::Issued,
            'certificate' => self::CRT, 'private_key' => null])->save();

        $this->assertFalse(app(CertificateHosting::class)->canInstall($service->fresh()));
        $this->assertFalse(app(CertificateHosting::class)->install($service->fresh()));
        $this->assertSame([], $this->whmCertificates);
    }

    public function test_only_a_file_under_pki_validation_on_the_domain_or_a_record_in_its_zone_is_put_in_place(): void
    {
        $service = $this->ssl();
        $details = fn (string $method, array $validation) => CertificateHosting::details(tap(new Certificate(['dcv_method' => $method, 'validation' => $validation]))->setRelation('service', $service));

        $this->assertSame(['path' => '/.well-known/pki-validation/A.txt', 'content' => 'x'], $details('http', ['http' => ['link' => 'http://www.maria.gr/.well-known/pki-validation/A.txt', 'content' => 'x']]));
        $this->assertNull($details('http', ['http' => ['link' => 'http://maria.gr/.htaccess', 'content' => 'x']]));
        $this->assertNull($details('http', ['http' => ['link' => 'http://maria.gr/.well-known/pki-validation/shell.php', 'content' => '<?php']]));
        $this->assertNull($details('http', ['http' => ['link' => 'http://maria.gr/.well-known/pki-validation/a.php.txt', 'content' => 'x']]));
        $this->assertNull($details('http', ['http' => ['link' => 'http://other.gr/.well-known/pki-validation/A.txt', 'content' => 'x']]));
        $this->assertNull($details('http', ['http' => ['link' => 'http://maria.gr/.well-known/pki-validation/A.txt', 'content' => '']]));
        $this->assertSame(['name' => '_abc.maria.gr', 'type' => 'CNAME', 'value' => 'xyz.sectigo.com'], $details('dns', ['dns' => ['record' => '_abc.maria.gr. CNAME xyz.sectigo.com.']]));
        $this->assertNull($details('dns', ['dns' => ['record' => '_abc.other.gr CNAME xyz.sectigo.com']]));
    }

    public function test_the_service_pages_say_it_is_in_place_and_the_admin_installs_it_by_hand(): void
    {
        $this->fakeWhm();
        $this->whmAccounts['maria'] = false;
        $this->hosting(Server::factory()->create());
        $service = $this->ssl();
        $this->method = 'http';
        app(SetUpService::class)->handle($service);

        $this->actingAs(User::factory()->create());
        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertOk()
            ->assertSee(__('Already in place on the hosting account :domain: nothing to do, it is checked automatically.', ['domain' => 'maria.gr']), escape: false)
            ->assertActionHidden('installCertificate');

        $this->whmFailures['SSL::install_ssl'] = 'Busy';
        $this->issue();
        app(CertificateLifecycle::class)->refresh($service->fresh());
        unset($this->whmFailures['SSL::install_ssl']);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertActionVisible('installCertificate')
            ->callAction('installCertificate')
            ->assertNotified(__('Installed'));
        $this->assertArrayHasKey('maria.gr', $this->whmCertificates);

        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');

        Livewire::test(ViewService::class, ['record' => $service->getRouteKey()])
            ->assertOk()
            ->assertSee(__(':domain: installed on :date', ['domain' => 'maria.gr', 'date' => today()->format('d/m/Y')]));
    }
}
