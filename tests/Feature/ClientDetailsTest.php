<?php

namespace Tests\Feature;

use App\Enums\MailTemplate;
use App\Enums\ServiceType;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Mail\ClientMail;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ClientDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->actingAs(User::factory()->create());
    }

    public function test_create_client_with_registrant_and_tax_details(): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm([
                'first_name' => 'Γιώργος',
                'last_name' => 'Παπαδόπουλος',
                'company' => 'Acme ΙΚΕ',
                'email' => 'giorgos@example.gr',
                'phone' => '+30 6912345678',
                'address1' => 'Ερμού 1',
                'city' => 'Αθήνα',
                'state' => 'Attica',
                'postcode' => '10563',
                'country' => 'GR',
                'vat_number' => '123456789',
                'tax_office' => 'Α\' Αθηνών',
                'profession' => 'Web design',
                'default_payment_method' => 'cash',
                'overdue_notices' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::sole();
        $this->assertSame('Γιώργος Παπαδόπουλος', $client->name);
        $this->assertSame('Γιώργος Παπαδόπουλος (Acme ΙΚΕ)', $client->displayName());
        $this->assertSame('Α\' Αθηνών', $client->tax_office);
        $this->assertSame('cash', $client->default_payment_method);
        $this->assertTrue($client->renewal_reminders);
        $this->assertFalse($client->overdue_notices);
    }

    public function test_first_last_name_email_and_country_are_required(): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm(['country' => null])
            ->call('create')
            ->assertHasFormErrors(['first_name' => 'required', 'last_name' => 'required', 'email' => 'required', 'country' => 'required']);
    }

    public function test_reminder_preferences_are_respected(): void
    {
        Mail::fake();
        config(['gaiacp.reminders.before_days' => [7], 'gaiacp.reminders.overdue_days' => [1]]);

        $noRenewals = Client::factory()->create(['renewal_reminders' => false]);
        $noOverdue = Client::factory()->create(['overdue_notices' => false]);
        Service::factory()->for($noRenewals)->dueOn('2026-10-10')->create(); // upcoming: skipped
        Service::factory()->for($noRenewals)->dueOn('2026-10-01')->create(); // overdue: sent
        Service::factory()->for($noOverdue)->dueOn('2026-10-01')->create();  // overdue: skipped

        $this->artisan('gaiacp:send-reminders', ['--date' => '2026-10-08'])->assertSuccessful();

        // A payment notice for each; noRenewals gets no "created" email, but the overdue one gets the first overdue
        // notice. noOverdue gets its notice, never an overdue reminder.
        $this->assertDatabaseCount('invoices', 3);
        Mail::assertSentCount(2);
        Mail::assertSent(ClientMail::class, fn (ClientMail $m) => $m->hasTo($noRenewals->email) && $m->template() === MailTemplate::InvoiceFirstOverdue);
        Mail::assertSent(ClientMail::class, fn (ClientMail $m) => $m->hasTo($noOverdue->email) && $m->template() === MailTemplate::InvoiceCreated);

        $this->artisan('gaiacp:send-reminders', ['--date' => '2026-10-09'])->assertSuccessful();
        Mail::assertSentCount(2);
    }

    public function test_record_payment_preselects_the_clients_default_method(): void
    {
        $service = Service::factory()->domain()->for(Client::factory()->state(['default_payment_method' => 'cash']))->create();

        Livewire::test(EditService::class, ['record' => $service->id])
            ->mountAction('recordPayment')
            ->assertSchemaStateSet(['method' => 'cash'], 'mountedActionSchema0');
    }

    public function test_picking_a_product_fills_the_service(): void
    {
        $product = Product::factory()->maintenance()->priced(15000)->create();
        $client = Client::factory()->create();

        Livewire::test(CreateService::class)
            ->fillForm(['client_id' => $client->id, 'product_id' => $product->id])
            ->assertSchemaStateSet(['type' => $product->type, 'label' => 'Maintenance', 'price_cents' => '150.00'])
            ->fillForm(['domain' => 'client-site.gr'])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::sole();
        $this->assertSame('maintenance', $service->type->value);
        $this->assertSame($product->id, $service->product_id);
        $this->assertSame(15000, $service->price_cents);
        $this->assertNull($service->server_id);
    }

    public function test_maintenance_needs_no_server_or_domain(): void
    {
        $client = Client::factory()->create();

        Livewire::test(CreateService::class)
            ->fillForm(['client_id' => $client->id, 'type' => 'maintenance', 'label' => 'Website maintenance', 'price_cents' => '120', 'billing_cycle' => 'annually'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('maintenance', Service::sole()->type->value);
    }

    public function test_clearing_the_product_undoes_what_it_filled_in(): void
    {
        $product = Product::factory()->priced(1500)->create(['name' => '.gr domain', 'type' => 'domain', 'package' => null]);

        Livewire::test(CreateService::class)
            ->fillForm(['product_id' => $product->id])
            ->assertSchemaStateSet(['type' => ServiceType::Domain, 'label' => '.gr domain'])
            ->assertSchemaComponentVisible('domainDetails', 'form')
            ->fillForm(['product_id' => null])
            ->assertSchemaStateSet(['type' => ServiceType::Hosting, 'label' => null, 'price_cents' => null]);
    }

    public function test_a_domain_needs_no_label(): void
    {
        $client = Client::factory()->create();

        Livewire::test(CreateService::class)
            ->fillForm(['client_id' => $client->id, 'type' => 'domain'])
            ->assertSchemaComponentHidden('label', 'form')
            ->fillForm(['domain' => 'acme.gr', 'price_cents' => '15', 'billing_cycle' => 'annually'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('acme.gr', Service::sole()->label);
    }
}
