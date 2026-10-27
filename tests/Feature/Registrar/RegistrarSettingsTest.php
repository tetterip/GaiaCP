<?php

namespace Tests\Feature\Registrar;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Registrars\Pages\CreateRegistrar;
use App\Filament\Resources\Registrars\Pages\EditRegistrar;
use App\Filament\Resources\Registrars\RegistrarResource;
use App\Filament\Resources\Tlds\TldResource;
use App\Models\Registrar;
use App\Models\Tld;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\FakesEasy;
use Tests\TestCase;

class RegistrarSettingsTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_pages_render_and_are_linked_from_settings(): void
    {
        Registrar::factory()->withTlds()->create();

        $this->get(SettingsCluster::getUrl())->assertOk()->assertSee(RegistrarResource::getUrl())->assertSee(TldResource::getUrl());
        $this->get(RegistrarResource::getUrl())->assertOk()->assertSee('Easy.gr')->assertSee('Email Notifications');
        $this->get(RegistrarResource::getUrl('create'))->assertOk()->assertDontSee('TLDs and prices');
    }

    public function test_create_a_registrar_account(): void
    {
        // Email Notifications is there from the start but is no real registrar, so the first real one is the default.
        Livewire::test(CreateRegistrar::class)
            ->assertSchemaStateSet(['module' => 'easygr', 'is_default' => true])
            ->fillForm([
                'name' => 'Easy.gr',
                'credentials' => ['username' => 'reseller', 'password' => 'p@ss'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $registrar = Registrar::query()->where('module', 'easygr')->sole();
        $this->assertTrue($registrar->is_default);
        $this->assertSame(['username' => 'reseller', 'password' => 'p@ss'], $registrar->credentials);
        $this->assertStringNotContainsString('p@ss', DB::table('registrars')->where('module', 'easygr')->value('credentials'));
    }

    public function test_blank_password_keeps_the_stored_one(): void
    {
        $registrar = Registrar::factory()->create();

        Livewire::test(EditRegistrar::class, ['record' => $registrar->id])
            ->assertSchemaStateSet(['credentials.password' => null])
            ->fillForm(['credentials' => ['username' => 'renamed', 'password' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['username' => 'renamed', 'password' => 'secret'], $registrar->refresh()->credentials);
    }

    public function test_edit_form_shows_the_stored_username(): void
    {
        $registrar = Registrar::factory()->create();

        $page = Livewire::test(EditRegistrar::class, ['record' => $registrar->id])
            ->assertSchemaStateSet(['credentials.username' => $registrar->credentials['username'], 'credentials.password' => null]);

        // The password never reaches the page.
        $this->assertStringNotContainsString($registrar->credentials['password'], json_encode($page->snapshot));

        $page->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($registrar->credentials, $registrar->refresh()->credentials);
    }

    public function test_only_one_default(): void
    {
        $first = Registrar::factory()->create();
        Registrar::factory()->create(['name' => 'Other']);

        $this->assertFalse($first->refresh()->is_default);
    }

    public function test_a_domain_goes_to_its_tld_s_preferred_registrar(): void
    {
        $gr = Registrar::factory()->withTlds(['.gr'])->create(['name' => 'GR', 'is_default' => false]);
        $comGr = Registrar::factory()->withTlds(['.com.gr'])->create(['name' => 'COM.GR']);
        Registrar::factory()->withTlds(['.eu'])->create(['name' => 'Off', 'active' => false, 'is_default' => false]);
        Tld::query()->create(['tld' => '.de', 'register_1y_cents' => 1000]);

        $this->assertSame($comGr->id, Registrar::forDomain('shop.com.gr')->id); // the longest match
        $this->assertSame($gr->id, Registrar::forDomain('shop.gr')->id);
        $this->assertNull(Registrar::forDomain('shop.eu')); // its registrar is off
        $this->assertNull(Registrar::forDomain('shop.de')); // priced, but no preferred registrar
        $this->assertNull(Registrar::forDomain('gr'));

        // A TLD no longer offered keeps handing its domains to its registrar (renewals).
        Tld::query()->where('tld', '.gr')->update(['active' => false]);
        $this->assertSame($gr->id, Registrar::forDomain('shop.gr')->id);
    }

    public function test_check_action_reads_a_domain(): void
    {
        $this->fakeEasy();
        $this->easyDomains['acme.gr'] = ['expires' => '2027-03-15', 'ns' => ['ns1.host.gr']];

        Livewire::test(EditRegistrar::class, ['record' => $this->easyRegistrar->id])
            ->callAction('checkDomain', ['domain' => 'ACME.gr'])
            ->assertNotified('Connected: acme.gr expires 15/03/2027');
    }
}
