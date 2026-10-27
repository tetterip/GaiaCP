<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Plugins\Aade\AadeException;
use Plugins\Aade\AadeRegistry;
use Plugins\Aade\AadeSettings;
use Plugins\Aade\Afm;
use Tests\TestCase;

class AadeLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gaiacp.aade' => ['enabled' => true, 'username' => 'ACMECODE', 'password' => 'p&ss<word>', 'afm_called_by' => null]]);
        $this->actingAs(User::factory()->create());
    }

    public function test_afm_check_digit(): void
    {
        $this->assertTrue(Afm::isValid('094014201'));
        $this->assertTrue(Afm::isValid(Afm::clean('EL 094 014 201')));
        $this->assertFalse(Afm::isValid('094014202'));
        $this->assertFalse(Afm::isValid('000000000'));
        $this->assertFalse(Afm::isValid('12345678'));
    }

    public function test_lookup_sends_the_special_codes_and_reads_the_registry_details(): void
    {
        Http::fake([AadeRegistry::ENDPOINT => Http::response(self::reply())]);

        $result = AadeRegistry::lookup('094014201');

        $this->assertSame('094014201', $result['afm']);
        $this->assertSame('ΠΑΡΑΔΕΙΓΜΑ ΜΟΝΟΠΡΟΣΩΠΗ Ι Κ Ε', $result['name']);
        $this->assertSame('ΚΕΦΟΔΕ ΑΤΤΙΚΗΣ', $result['tax_office']);
        $this->assertSame('ΥΠΗΡΕΣΙΕΣ ΦΙΛΟΞΕΝΙΑΣ ΙΣΤΟΣΕΛΙΔΩΝ', $result['profession']);
        $this->assertSame('ΕΡΜΟΥ 10', $result['address']);
        $this->assertSame('10563', $result['postcode']);
        $this->assertSame('ΑΘΗΝΑ', $result['city']);
        $this->assertTrue($result['active']);

        Http::assertSent(function (Request $request) {
            return $request->url() === AadeRegistry::ENDPOINT
                && str_starts_with($request->header('Content-Type')[0], 'application/soap+xml')
                && str_contains($request->body(), '<ns1:Username>ACMECODE</ns1:Username>')
                && str_contains($request->body(), '<ns1:Password>p&amp;ss&lt;word&gt;</ns1:Password>')
                && str_contains($request->body(), '<ns3:afm_called_for>094014201</ns3:afm_called_for>');
        });
        $this->assertSame('aade.lookup', ActivityLog::sole()->event);
    }

    public function test_an_invalid_afm_never_reaches_aade(): void
    {
        Http::fake();

        $this->expectException(AadeException::class);
        $this->expectExceptionMessage('not a valid ΑΦΜ');

        try {
            AadeRegistry::lookup('094014202');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_registry_errors_are_shown_and_logged(): void
    {
        Http::fake([AadeRegistry::ENDPOINT => Http::response(self::errorReply('RG_WS_PUBLIC_TOKEN_USERNAME_NOT_AUTHENTICATED', 'Ο χρήστης δεν έχει πιστοποιηθεί.'))]);

        try {
            AadeRegistry::lookup('094014201');
            $this->fail('No exception');
        } catch (AadeException $e) {
            $this->assertStringContainsString('Ο χρήστης δεν έχει πιστοποιηθεί.', $e->getMessage());
            $this->assertStringContainsString('special access codes', $e->getMessage());
        }

        $this->assertSame('aade.failed', ActivityLog::sole()->event);
    }

    public function test_lookup_needs_the_codes(): void
    {
        config(['gaiacp.aade.password' => null]);
        Http::fake();

        $this->expectExceptionMessage('Settings → ΑΑΔΕ');

        AadeRegistry::lookup('094014201');
    }

    public function test_switched_off_hides_the_button_and_never_calls_aade(): void
    {
        config(['gaiacp.aade.enabled' => false]);
        Http::fake();

        Livewire::test(CreateClient::class)
            ->assertActionHidden(TestAction::make('aadeLookup')->schemaComponent('vat_number', 'form'));

        try {
            AadeRegistry::lookup('094014201');
            $this->fail('No exception');
        } catch (AadeException $e) {
            $this->assertStringContainsString('switched off', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_client_form_button_fills_in_the_details(): void
    {
        Http::fake([AadeRegistry::ENDPOINT => Http::response(self::reply())]);

        Livewire::test(CreateClient::class)
            ->fillForm(['vat_number' => '094014201', 'country' => 'CY'])
            ->callAction(TestAction::make('aadeLookup')->schemaComponent('vat_number', 'form'))
            ->assertSchemaStateSet([
                'company' => 'ΠΑΡΑΔΕΙΓΜΑ ΜΟΝΟΠΡΟΣΩΠΗ Ι Κ Ε',
                'tax_office' => 'ΚΕΦΟΔΕ ΑΤΤΙΚΗΣ',
                'profession' => 'ΥΠΗΡΕΣΙΕΣ ΦΙΛΟΞΕΝΙΑΣ ΙΣΤΟΣΕΛΙΔΩΝ',
                'address1' => 'ΕΡΜΟΥ 10',
                'postcode' => '10563',
                'city' => 'ΑΘΗΝΑ',
                'country' => 'GR',
            ]);
    }

    public function test_client_form_button_keeps_the_fields_when_the_lookup_fails(): void
    {
        Http::fake([AadeRegistry::ENDPOINT => Http::response(self::errorReply('RG_WS_PUBLIC_EPIT_NF', 'Ο Α.Φ.Μ. για τον οποίο ζητούνται πληροφορίες δεν αντιστοιχεί σε επιτηδευματία.'))]);
        $client = Client::factory()->create(['company' => 'Acme', 'vat_number' => '094014201']);

        Livewire::test(EditClient::class, ['record' => $client->getKey()])
            ->callAction(TestAction::make('aadeLookup')->schemaComponent('vat_number', 'form'))
            ->assertNotified('ΑΑΔΕ lookup failed')
            ->assertSchemaStateSet(['company' => 'Acme']);
    }

    public function test_settings_page_stores_the_password_encrypted(): void
    {
        Livewire::test(AadeSettings::class)
            ->assertSet('data.aade.password', null)
            ->fillForm(['aade' => ['enabled' => true, 'username' => 'NEWCODE', 'password' => 'secret', 'afm_called_by' => 'EL094014201']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.aade.password', null);

        $this->assertNotSame('secret', Setting::query()->find('aade.password')->value);
        $this->assertSame('secret', config('gaiacp.aade.password'));
        $this->assertSame('094014201', config('gaiacp.aade.afm_called_by'));
        $this->assertTrue(config('gaiacp.aade.enabled'));

        // Blank keeps the stored password.
        Livewire::test(AadeSettings::class)->fillForm(['aade' => ['enabled' => true, 'username' => 'NEWCODE', 'password' => '']])->call('save')->assertHasNoFormErrors();
        $this->assertSame('secret', config('gaiacp.aade.password'));
    }

    public function test_settings_page_test_lookup(): void
    {
        Http::fake([AadeRegistry::ENDPOINT => Http::response(self::reply())]);

        Livewire::test(AadeSettings::class)
            ->callAction(TestAction::make('testLookup')->schemaComponent('aadeActions', 'form'), data: ['afm' => '094014201'])
            ->assertNotified('ΑΑΔΕ answered');
    }

    private static function reply(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"><env:Header/><env:Body><srvc:rgWsPublic2AfmMethodResponse xmlns:srvc="http://rgwspublic2/RgWsPublic2Service" xmlns="http://rgwspublic2/RgWsPublic2"><srvc:result><rg_ws_public2_result_rtType><call_seq_id>123</call_seq_id><error_rec><error_code xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/><error_descr xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/></error_rec><basic_rec><afm>094014201</afm><doy>1159</doy><doy_descr>ΚΕΦΟΔΕ ΑΤΤΙΚΗΣ</doy_descr><i_ni_flag_descr>ΜΗ ΦΠ</i_ni_flag_descr><deactivation_flag>1</deactivation_flag><deactivation_flag_descr>ΕΝΕΡΓΟΣ ΑΦΜ</deactivation_flag_descr><firm_flag_descr>ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ</firm_flag_descr><onomasia>ΠΑΡΑΔΕΙΓΜΑ ΜΟΝΟΠΡΟΣΩΠΗ Ι Κ Ε</onomasia><commer_title>ΠΑΡΑΔΕΙΓΜΑ</commer_title><legal_status_descr>ΙΚΕ</legal_status_descr><postal_address>ΕΡΜΟΥ</postal_address><postal_address_no>10</postal_address_no><postal_zip_code>10563</postal_zip_code><postal_area_description>ΑΘΗΝΑ</postal_area_description></basic_rec><firm_act_tab><item><firm_act_code>62010000</firm_act_code><firm_act_descr>ΔΡΑΣΤΗΡΙΟΤΗΤΕΣ ΠΡΟΓΡΑΜΜΑΤΙΣΜΟΥ</firm_act_descr><firm_act_kind>2</firm_act_kind><firm_act_kind_descr>ΔΕΥΤΕΡΕΥΟΥΣΑ</firm_act_kind_descr></item><item><firm_act_code>63110000</firm_act_code><firm_act_descr>ΥΠΗΡΕΣΙΕΣ ΦΙΛΟΞΕΝΙΑΣ ΙΣΤΟΣΕΛΙΔΩΝ</firm_act_descr><firm_act_kind>1</firm_act_kind><firm_act_kind_descr>ΚΥΡΙΑ</firm_act_kind_descr></item></firm_act_tab></rg_ws_public2_result_rtType></srvc:result></srvc:rgWsPublic2AfmMethodResponse></env:Body></env:Envelope>
XML;
    }

    private static function errorReply(string $code, string $description): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"><env:Body><srvc:rgWsPublic2AfmMethodResponse xmlns:srvc="http://rgwspublic2/RgWsPublic2Service" xmlns="http://rgwspublic2/RgWsPublic2"><srvc:result><rg_ws_public2_result_rtType><error_rec><error_code>{$code}</error_code><error_descr>{$description}</error_descr></error_rec></rg_ws_public2_result_rtType></srvc:result></srvc:rgWsPublic2AfmMethodResponse></env:Body></env:Envelope>
XML;
    }
}
