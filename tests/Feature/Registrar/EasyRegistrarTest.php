<?php

namespace Tests\Feature\Registrar;

use App\Models\Client;
use App\Models\Registrar;
use App\Provisioning\DomainRegistrar;
use App\Provisioning\ProvisioningException;
use App\Provisioning\RegistrarFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Plugins\EasyGr\EasyRegistrar;
use Tests\FakesEasy;
use Tests\TestCase;

class EasyRegistrarTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    private function registrar(): DomainRegistrar
    {
        return app(RegistrarFactory::class)->make('easygr', ['username' => 'reseller', 'password' => 'secret']);
    }

    public function test_posts_credentials_and_action_as_a_form(): void
    {
        $this->fakeEasy();
        $this->easyDomains['acme.gr'] = ['expires' => '2027-03-15'];

        $this->assertSame('2027-03-15', $this->registrar()->expiryDate('acme.gr')->toDateString());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.easy.gr'
            && $request->method() === 'POST'
            && $request['username'] === 'reseller'
            && $request['password'] === 'secret'
            && $request->isForm()
            && (int) $request['action'] === EasyRegistrar::SYNC
            && $request['domain'] === 'acme.gr');
    }

    public function test_register_sends_the_client_as_all_four_contacts(): void
    {
        $this->fakeEasy();
        $client = Client::factory()->create([
            'first_name' => 'Νίκος', 'last_name' => 'Παπαδόπουλος', 'company' => null,
            'email' => 'nikos@example.gr', 'phone' => '210 123 4567',
            'address1' => 'Ερμού 1', 'address2' => null, 'city' => 'Αθήνα', 'state' => 'Αττική', 'postcode' => '10563', 'country' => 'gr',
        ]);

        $this->registrar()->register('acme.gr', 2, $client, ['ns1.host.gr', 'ns2.host.gr']);

        [$action, $params] = $this->easyCalls[0];
        $this->assertSame(EasyRegistrar::REGISTER, $action);
        $this->assertSame(['acme.gr', '2', 'ns1.host.gr', 'ns2.host.gr', ''], [$params['domain'], $params['years'], $params['ns1'], $params['ns2'], $params['ns4']]);

        foreach ([1, 2, 3, 4] as $i) {
            $this->assertSame('Νίκος Παπαδόπουλος', $params["name{$i}"]);
            $this->assertSame('Ερμού 1', $params["street1{$i}"]);
            $this->assertSame('', $params["street2{$i}"]);
            $this->assertSame('Αθήνα', $params["city{$i}"]);
            $this->assertSame('10563', $params["pc{$i}"]);
            $this->assertSame('Αττική', $params["sp{$i}"]);
            $this->assertSame('GR', $params["cc{$i}"]);
            $this->assertSame('+30.2101234567', $params["voice{$i}"]);
            $this->assertSame('nikos@example.gr', $params["email{$i}"]);
        }
    }

    public function test_register_without_a_region_says_which_client_fields_to_fill_in(): void
    {
        $this->fakeEasy();
        $client = Client::factory()->create(['state' => null, 'city' => ' ']);

        try {
            $this->registrar()->register('acme.digital', 1, $client, []);
            $this->fail('No exception thrown.');
        } catch (ProvisioningException $e) {
            $this->assertSame("easy.gr needs the client's City and State / region for the domain contact: fill them in on the client and try again.", $e->getMessage());
        }

        $this->assertSame([], $this->easyCalls);
    }

    public function test_company_name_is_the_registrant_name_when_set(): void
    {
        $this->fakeEasy();
        $client = Client::factory()->create(['company' => 'Acme ΙΚΕ']);

        $this->registrar()->transfer('acme.gr', 'Auth-123', $client);

        [$action, $params] = $this->easyCalls[0];
        $this->assertSame(EasyRegistrar::TRANSFER, $action);
        $this->assertSame(['Acme ΙΚΕ', 'Auth-123'], [$params['name'], $params['pw']]);
        $this->assertArrayNotHasKey('name1', $params);
    }

    public function test_reads_and_saves_nameservers(): void
    {
        $this->fakeEasy();
        $this->easyDomains['acme.gr'] = ['expires' => '2027-03-15', 'ns' => ['NS1.Host.gr', 'ns2.host.gr']];

        $this->assertSame(['ns1.host.gr', 'ns2.host.gr'], $this->registrar()->nameservers('acme.gr'));

        $this->registrar()->setNameservers('acme.gr', ['a.ns.gr', 'b.ns.gr', 'c.ns.gr']);
        $this->assertSame(['domain' => 'acme.gr', 'ns1' => 'a.ns.gr', 'ns2' => 'b.ns.gr', 'ns3' => 'c.ns.gr', 'ns4' => ''], $this->easyCalls[1][1]);
    }

    public function test_more_than_four_nameservers_are_refused(): void
    {
        $this->fakeEasy();

        $this->expectException(ProvisioningException::class);
        $this->registrar()->setNameservers('acme.gr', ['a.gr', 'b.gr', 'c.gr', 'd.gr', 'e.gr']);
    }

    public function test_errors_are_joined_like_the_whmcs_module(): void
    {
        $this->fakeEasy();
        $this->easyFailures[EasyRegistrar::RENEW] = ['2105' => 'Λάθος περίοδος', '2306' => 'Not allowed'];

        $this->expectExceptionMessage('easy.gr: 2105 - Λάθος περίοδος, 2306 - Not allowed');
        $this->registrar()->renew('acme.gr', 1);
    }

    public function test_http_error_shows_what_easy_gr_said(): void
    {
        Http::fake(['*' => Http::response(['done' => 0, 'errors' => ['2306' => 'TLD not allowed']], 500)]);

        $this->expectExceptionMessage('easy.gr action 4 failed with HTTP 500: 2306 - TLD not allowed.');
        $this->registrar()->register('acme.com', 1, Client::factory()->create(), []);
    }

    public function test_http_error_page_is_shown_as_short_text(): void
    {
        Http::fake(['*' => Http::response("<html><head><title>x</title></head><body><h1>Server Error</h1>\n<p>Undefined index: sp1</p></body></html>", 500)]);

        $this->expectExceptionMessage('easy.gr action 4 failed with HTTP 500: Server Error Undefined index: sp1.');
        $this->registrar()->register('acme.com', 1, Client::factory()->create(), []);
    }

    public function test_empty_http_error_says_so(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->expectExceptionMessage('easy.gr action 19 failed with HTTP 500 (empty reply).');
        $this->registrar()->expiryDate('acme.gr');
    }

    public function test_registry_down_is_reported(): void
    {
        Http::fake(['*' => Http::response(['done' => 3])]);

        $this->expectExceptionMessage('cannot reach the .gr registry');
        $this->registrar()->expiryDate('acme.gr');
    }

    public function test_unexpected_response_is_an_error(): void
    {
        Http::fake(['*' => Http::response('<html>maintenance</html>')]);

        $this->expectExceptionMessage('unexpected response');
        $this->registrar()->expiryDate('acme.gr');
    }

    public function test_missing_credentials_never_call_the_api(): void
    {
        $registrar = app(RegistrarFactory::class)->make('easygr', ['username' => '', 'password' => 'secret']);

        $this->assertFalse($registrar->configured());

        try {
            $registrar->expiryDate('acme.gr');
            $this->fail('Expected an exception');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('Settings → Registrars', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_epp_code(): void
    {
        $this->fakeEasy();
        $this->easyDomains['acme.gr'] = ['expires' => '2027-03-15', 'epp' => 'Xy-9!'];

        $this->assertSame('Xy-9!', $this->registrar()->eppCode('acme.gr'));
    }

    public function test_tls_verification_follows_the_registrar_setting(): void
    {
        $verify = [];
        Http::fake(function (Request $request, array $options) use (&$verify) {
            $verify[] = $options['verify'] ?? null;

            return Http::response(['done' => 1, 'date_expire' => '2027-03-15 00:00:00']);
        });
        $registrar = Registrar::factory()->create();
        $factory = app(RegistrarFactory::class);

        $factory->forRegistrar($registrar)->expiryDate('acme.gr');
        $registrar->update(['verify_tls' => false]);
        $factory->forRegistrar($registrar)->expiryDate('acme.gr');

        $this->assertSame([true, false], $verify);
    }

    public function test_inactive_registrar_is_refused(): void
    {
        $this->expectExceptionMessage('inactive');
        app(RegistrarFactory::class)->forRegistrar(Registrar::factory()->create(['active' => false]));
    }

    public function test_check_command_reads_without_changing_anything(): void
    {
        $this->fakeEasy();
        $this->easyDomains['acme.gr'] = ['expires' => '2027-03-15', 'ns' => ['ns1.host.gr', 'ns2.host.gr']];

        $this->artisan('gaiacp:registrar-check', ['domain' => 'acme.gr'])
            ->expectsOutput('acme.gr at Easy.gr: expires 2027-03-15')
            ->expectsOutput('Nameservers: ns1.host.gr, ns2.host.gr')
            ->assertSuccessful();

        $this->assertSame([EasyRegistrar::SYNC, EasyRegistrar::GET_NAMESERVERS], $this->easyActions());
    }

    public function test_check_command_shows_the_error(): void
    {
        $this->fakeEasy();

        $this->artisan('gaiacp:registrar-check', ['domain' => 'missing.gr'])
            ->expectsOutput('easy.gr: 2303 - Object does not exist (missing.gr is not in this easy.gr account)')
            ->assertFailed();
    }

    public function test_phone_formats(): void
    {
        $this->assertSame('+30.6971234567', EasyRegistrar::phone('6971234567'));
        $this->assertSame('+30.2101234567', EasyRegistrar::phone('+30 210 123 4567'));
        $this->assertSame('+30.2101234567', EasyRegistrar::phone('00302101234567'));
        $this->assertSame('+30.2101234567', EasyRegistrar::phone('302101234567'));
        $this->assertSame('+357.22123456', EasyRegistrar::phone('+357 22 123456'));
        $this->assertSame('+49.301234567', EasyRegistrar::phone('+49.301234567'));
        $this->assertSame('', EasyRegistrar::phone(null));
    }
}
