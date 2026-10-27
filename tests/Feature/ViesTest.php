<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Invoices\GenerateInvoices;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Vies\Vies;
use Plugins\Vies\ViesCheck;
use Plugins\Vies\ViesException;
use Plugins\Vies\ViesSettings;
use Tests\TestCase;

class ViesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('vies'));

        config([
            'gaiacp.region.country' => 'GR',
            'gaiacp.company.vat_number' => '094014201',
            'gaiacp.tax' => ['enabled' => true, 'inclusive' => false, 'rules' => [['name' => 'VAT', 'rate' => 24, 'country' => null]]],
            'gaiacp.vies.reverse_charge' => true,
        ]);
        $this->actingAs(User::factory()->create());
    }

    /** @param  array<string, mixed>  $answer */
    private function fakeVies(array $answer = [], int $status = 200): void
    {
        Http::fake([Vies::ENDPOINT => Http::response($answer + [
            'countryCode' => 'DE', 'vatNumber' => '123456789', 'requestDate' => '2026-10-25T10:00:00.000Z',
            'valid' => true, 'requestIdentifier' => 'WAPIAAAAZ1x2', 'name' => 'BEISPIEL GMBH', 'address' => '---',
        ], $status)]);
    }

    private function euClient(array $attributes = []): Client
    {
        return Client::factory()->create($attributes + ['company' => 'Beispiel GmbH', 'country' => 'DE', 'vat_number' => 'DE 123 456 789']);
    }

    public function test_numbers_are_sent_without_their_prefix_and_greece_is_el(): void
    {
        $this->assertSame(['EL', '094014201'], Vies::normalize('EL 094-014.201', 'GR'));
        $this->assertSame(['EL', '094014201'], Vies::normalize('GR094014201', 'gr'));
        $this->assertSame(['DE', '123456789'], Vies::normalize('de123456789', 'DE'));
        $this->assertSame(['AT', 'U12345678'], Vies::normalize('ATU12345678', 'AT'));

        $this->expectException(ViesException::class);
        Vies::normalize('123', 'US');
    }

    public function test_a_check_asks_vies_with_the_requester_and_keeps_the_answer(): void
    {
        $this->fakeVies();

        $check = Vies::check('DE 123456789', 'DE');

        $this->assertTrue($check->valid);
        $this->assertSame('BEISPIEL GMBH', $check->name);
        $this->assertNull($check->address); // VIES's "---"
        $this->assertSame('WAPIAAAAZ1x2', $check->request_identifier);
        $this->assertSame(1, ViesCheck::query()->count());
        $this->assertTrue(ActivityLog::query()->where('event', 'vies.checked')->exists());

        Http::assertSent(fn (Request $request) => $request->url() === Vies::ENDPOINT && $request->data() === [
            'countryCode' => 'DE', 'vatNumber' => '123456789', 'requesterMemberStateCode' => 'EL', 'requesterNumber' => '094014201',
        ]);
    }

    public function test_a_vies_error_keeps_nothing(): void
    {
        Http::fake([Vies::ENDPOINT => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE']]], 200)]);

        try {
            Vies::check('123456789', 'DE');
            $this->fail('No exception');
        } catch (ViesException $e) {
            $this->assertStringContainsString('not answering', $e->getMessage());
        }

        $this->assertSame(0, ViesCheck::query()->count());
        $this->assertTrue(ActivityLog::query()->where('event', 'vies.failed')->exists());
    }

    public function test_a_valid_eu_business_abroad_pays_no_tax_and_its_notice_says_why(): void
    {
        $this->fakeVies();
        $client = $this->euClient();
        Vies::check($client->vat_number, $client->country);

        $this->assertNull(Tax::ruleFor($client));
        $this->assertStringContainsString('Reverse charge', Tax::exemptionFor($client));
        $this->assertSame(['net' => 10000, 'tax' => 0, 'total' => 10000, 'rule' => null], Tax::totals($client, [10000]));

        Service::factory()->for($client)->create(['price_cents' => 10000, 'next_due_date' => '2026-11-01']);
        app(GenerateInvoices::class)->handle(CarbonImmutable::parse('2026-10-25'));

        $notice = Invoice::query()->where('client_id', $client->id)->sole();
        $this->assertSame(0, $notice->tax_cents);
        $this->assertStringContainsString('Article 196', $notice->tax_note);
    }

    public function test_tax_stays_without_a_valid_recent_check_of_the_number_now_on_the_client(): void
    {
        $client = $this->euClient();
        $rule = ['name' => 'VAT', 'rate' => 24.0];
        $this->assertSame($rule, Tax::ruleFor($client)); // never checked

        ViesCheck::create(['country_code' => 'DE', 'vat_number' => '123456789', 'valid' => false, 'checked_at' => now()]);
        $this->assertSame($rule, Tax::ruleFor($client)); // not valid

        ViesCheck::create(['country_code' => 'DE', 'vat_number' => '123456789', 'valid' => true, 'checked_at' => now()->subDays(400)]);
        $this->assertSame($rule, Tax::ruleFor($client->fresh())); // the latest is still the invalid one

        ViesCheck::query()->delete();
        ViesCheck::create(['country_code' => 'DE', 'vat_number' => '123456789', 'valid' => true, 'checked_at' => now()->subDays(400)]);
        $this->assertSame($rule, Tax::ruleFor($client)); // too old

        ViesCheck::create(['country_code' => 'DE', 'vat_number' => '123456789', 'valid' => true, 'checked_at' => now()]);
        $this->assertNull(Tax::ruleFor($client));

        $client->update(['vat_number' => 'DE999999999']);
        $this->assertSame($rule, Tax::ruleFor($client)); // a changed number needs its own check

        $greek = Client::factory()->create(['country' => 'GR', 'vat_number' => '094014201']);
        ViesCheck::create(['country_code' => 'EL', 'vat_number' => '094014201', 'valid' => true, 'checked_at' => now()]);
        $this->assertSame($rule, Tax::ruleFor($greek)); // same country as yours

        $client->update(['vat_number' => '123456789']);
        config(['gaiacp.vies.reverse_charge' => false]);
        $this->assertSame($rule, Tax::ruleFor($client));
        $this->assertNull(Tax::exemptionFor($client));

        config(['gaiacp.vies.reverse_charge' => true]);
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('vies'));
        $this->assertSame($rule, Tax::ruleFor($client));
    }

    public function test_client_form_button_checks_the_number_and_fills_an_empty_company(): void
    {
        $this->fakeVies();

        Livewire::test(CreateClient::class)
            ->fillForm(['vat_number' => 'DE123456789', 'country' => 'DE', 'company' => null])
            ->callAction(TestAction::make('viesCheck')->schemaComponent('vat_number', 'form'))
            ->assertNotified('Valid in VIES: DE123456789')
            ->assertSchemaStateSet(['company' => 'BEISPIEL GMBH']);
    }

    public function test_client_form_button_says_when_a_number_is_not_valid(): void
    {
        $this->fakeVies(['valid' => false, 'name' => '---']);
        $client = $this->euClient(['company' => 'Acme']);

        Livewire::test(EditClient::class, ['record' => $client->getKey()])
            ->callAction(TestAction::make('viesCheck')->schemaComponent('vat_number', 'form'))
            ->assertNotified('Not valid in VIES: DE123456789')
            ->assertSchemaStateSet(['company' => 'Acme']);
    }

    public function test_the_button_and_page_are_hidden_while_the_plugin_is_off(): void
    {
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('vies'));

        Livewire::test(CreateClient::class)
            ->assertActionHidden(TestAction::make('viesCheck')->schemaComponent('vat_number', 'form'));
        $this->assertFalse(ViesSettings::canAccess());
    }

    public function test_settings_are_saved(): void
    {
        Livewire::test(ViesSettings::class)
            ->fillForm(['vies' => ['home_country' => 'CY', 'requester_vat' => 'cy 1000 0000 X', 'reverse_charge' => true, 'valid_days' => 90, 'reverse_charge_note' => ' Reverse charge ']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('CY', config('gaiacp.vies.home_country'));
        $this->assertSame('CY10000000X', config('gaiacp.vies.requester_vat'));
        $this->assertSame(90, config('gaiacp.vies.valid_days'));
        $this->assertSame('Reverse charge', config('gaiacp.vies.reverse_charge_note'));
        $this->assertSame('CY', Vies::homeCountry());
    }
}
