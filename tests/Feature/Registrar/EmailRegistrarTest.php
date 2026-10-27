<?php

namespace Tests\Feature\Registrar;

use App\Actions\DomainLifecycle;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\Domains\Pages\ViewDomain;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Plugins\EmailRegistrar\RegistrarRequestMail;
use Tests\TestCase;

/** The built-in Email Notifications registrar: every registrar request is an email to the admins, nothing is booked. */
class EmailRegistrarTest extends TestCase
{
    use RefreshDatabase;

    private Registrar $registrar;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-14 10:00');
        Mail::fake();
        config(['gaiacp.domains.nameservers' => ['ns1.host.gr', 'ns2.host.gr']]);

        $this->registrar = Registrar::query()->where('module', 'email')->sole();
        Tld::query()->create(['tld' => '.de', 'registrar_id' => $this->registrar->id, 'register_1y_cents' => 1000, 'renew_1y_cents' => 1000]);
        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->client = Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Papa', 'company' => 'Acme']);
    }

    private function domain(ServiceStatus $status = ServiceStatus::Pending, array $details = []): Service
    {
        $service = Service::factory()->domain()->for($this->client)->create([
            'domain' => 'acme.de', 'status' => $status, 'billing_cycle' => BillingCycle::Biennially,
            'start_date' => '2026-10-14', 'next_due_date' => '2026-10-14',
        ]);
        $service->domainDetails()->create(['registration_type' => DomainRegistrationType::Register, ...$details]);

        return $service->refresh();
    }

    public function test_it_is_there_from_the_start_with_nothing_to_configure(): void
    {
        $this->assertTrue($this->registrar->active);
        $this->assertTrue($this->registrar->isManual());
        $this->assertNull($this->registrar->credentials);
        $this->assertSame($this->registrar->id, Registrar::forDomain('acme.de')->id);
    }

    public function test_registering_emails_the_admins_and_books_nothing(): void
    {
        $quiet = User::factory()->create(['email' => 'quiet@example.com', 'system_emails' => false]);
        $service = $this->domain();

        // Tests refuse any HTTP request, so a call to a registrar would fail here.
        app(DomainLifecycle::class)->activate($service);

        Mail::assertSent(RegistrarRequestMail::class, fn (RegistrarRequestMail $mail) => $mail->hasTo('admin@example.com')
            && $mail->request === RegistrarRequestMail::REGISTER && $mail->domain === 'acme.de' && $mail->details['years'] === 2
            && $mail->details['nameservers'] === ['ns1.host.gr', 'ns2.host.gr'] && $mail->service->is($service));
        Mail::assertNotSent(RegistrarRequestMail::class, fn (RegistrarRequestMail $mail) => $mail->hasTo($quiet->email));

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame($this->registrar->id, $service->domainDetails->registrar_id);
        $this->assertNull($service->domainDetails->expires_at);
        $this->assertTrue(ActivityLog::query()->where('event', 'domain.registered')->where('description', 'like', '%Email Notifications%')->exists());
    }

    public function test_the_email_names_the_registrant_and_links_the_domain(): void
    {
        $service = $this->domain();
        $html = (new RegistrarRequestMail(RegistrarRequestMail::REGISTER, 'acme.de', $service, ['years' => 2, 'registrant' => $this->client, 'nameservers' => ['ns1.host.gr', 'ns2.host.gr']]))->render();

        $this->assertStringContainsString('Register acme.de for 2 year(s)', $html);
        $this->assertStringContainsString('Maria Papa, Acme', $html);
        $this->assertStringContainsString('ns1.host.gr, ns2.host.gr', $html);
        $this->assertStringContainsString("/admin/services/{$service->id}/edit", $html);
    }

    public function test_with_no_admin_to_email_the_domain_stays_pending(): void
    {
        $this->admin->update(['system_emails' => false]);
        $service = $this->domain();

        try {
            app(DomainLifecycle::class)->activate($service);
            $this->fail('No admin was emailed, yet the domain was activated.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('emailed no admin', $e->getMessage());
        }

        $this->assertSame(ServiceStatus::Pending, $service->refresh()->status);
        $this->assertTrue(ActivityLog::query()->where('event', 'provisioning.failed')->exists());
    }

    public function test_a_renewal_is_emailed(): void
    {
        $service = $this->domain(ServiceStatus::Active, ['registrar_id' => $this->registrar->id, 'expires_at' => '2026-11-01']);

        app(DomainLifecycle::class)->renew($service);

        Mail::assertSent(RegistrarRequestMail::class, fn (RegistrarRequestMail $mail) => $mail->request === RegistrarRequestMail::RENEW && $mail->details['years'] === 2);
        $this->assertSame('2028-11-01', $service->refresh()->domainDetails->expires_at->toDateString());
    }

    public function test_nothing_is_read_back_so_sync_and_the_admin_epp_code_are_left_out(): void
    {
        $service = $this->domain(ServiceStatus::Active, ['registrar_id' => $this->registrar->id]);

        $this->assertFalse(app(DomainLifecycle::class)->readsRegistrar($service));
        $this->artisan('gaiacp:sync-domains')->expectsOutputToContain('Synced 0 domain(s)')->assertSuccessful();

        $this->actingAs($this->admin);
        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertActionHidden('syncDomain')
            ->assertActionHidden('eppCode')
            ->assertActionVisible('setNameservers');
    }

    public function test_the_client_s_epp_code_and_nameserver_changes_go_to_the_admins(): void
    {
        $service = $this->domain(ServiceStatus::Active, ['registrar_id' => $this->registrar->id, 'nameservers' => ['ns1.old.de', 'ns2.old.de']]);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');

        Livewire::test(ViewDomain::class, ['record' => $service->getRouteKey()])
            ->callAction('getEppCode')
            ->assertNotified(__('EPP code requested'));

        Livewire::test(ViewDomain::class, ['record' => $service->getRouteKey()])
            ->callAction('changeNameservers', ['ns1' => 'ns1.new.de', 'ns2' => 'ns2.new.de'])
            ->assertNotified(__('Change requested'));

        Mail::assertSent(RegistrarRequestMail::class, fn (RegistrarRequestMail $mail) => $mail->request === RegistrarRequestMail::EPP);
        Mail::assertSent(RegistrarRequestMail::class, fn (RegistrarRequestMail $mail) => $mail->request === RegistrarRequestMail::NAMESERVERS && $mail->details['nameservers'] === ['ns1.new.de', 'ns2.new.de']);
        $this->assertSame(['ns1.new.de', 'ns2.new.de'], $service->refresh()->domainDetails->nameservers);
    }
}
