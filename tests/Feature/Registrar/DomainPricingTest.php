<?php

namespace Tests\Feature\Registrar;

use App\Enums\BillingCycle;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Tlds\Pages\ManageTlds;
use App\Filament\Resources\Tlds\TldResource;
use App\Models\Client;
use App\Models\Order;
use App\Models\Registrar;
use App\Models\Tld;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\FakesEasy;
use Tests\TestCase;

/** Settings → Domain pricing: prices per TLD for every term, and the preferred registrar new orders start with. */
class DomainPricingTest extends TestCase
{
    use FakesEasy, RefreshDatabase;

    private Registrar $emailRegistrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->fakeEasy();
        $this->emailRegistrar = Registrar::query()->where('module', 'email')->sole();
    }

    public function test_the_page_lists_each_tld_with_its_prices_and_registrar(): void
    {
        Tld::query()->where('tld', '.gr')->update(['register_1y_cents' => null, 'register_2y_cents' => 2400, 'renew_2y_cents' => 2200]);

        $this->get(TldResource::getUrl())
            ->assertOk()
            ->assertSee('.com.gr')
            ->assertSee('Easy.gr')
            ->assertSee('2 years: €24.00')
            ->assertSee('2 years: €22.00');
    }

    public function test_admin_adds_a_tld_priced_for_every_term_with_the_default_registrar(): void
    {
        Livewire::test(ManageTlds::class)
            ->callAction('create', [
                'tld' => 'DE',
                'register_1y_cents' => '10,50',
                'register_3y_cents' => '30',
                'transfer_1y_cents' => '0',
                'renew_1y_cents' => '10.50',
                'renew_3y_cents' => '29.00',
                'grace_fee_cents' => '5',
                'grace_days' => 30,
            ])
            ->assertHasNoActionErrors();

        $tld = Tld::query()->where('tld', '.de')->sole();
        $this->assertSame($this->easyRegistrar->id, $tld->registrar_id); // the default registrar, pre-selected
        $this->assertSame([1050, null, 3000], [$tld->priceCents('register', 1), $tld->priceCents('register', 2), $tld->priceCents('register', 3)]);
        $this->assertSame([1, 3], $tld->terms('renew'));
        $this->assertSame(0, $tld->priceCents('transfer', 1));
        $this->assertSame([500, 30], [$tld->grace_fee_cents, $tld->grace_days]);
    }

    public function test_a_tld_is_listed_once(): void
    {
        Livewire::test(ManageTlds::class)
            ->callAction('create', ['tld' => '.GR'])
            ->assertHasActionErrors(['tld']);

        $this->assertSame(1, Tld::query()->where('tld', '.gr')->count());
    }

    public function test_the_preferred_registrar_of_many_tlds_is_set_at_once(): void
    {
        Livewire::test(ManageTlds::class)
            ->callTableBulkAction('setRegistrar', Tld::query()->whereIn('tld', ['.gr', '.com'])->get(), ['registrar_id' => $this->emailRegistrar->id])
            ->assertNotified();

        $this->assertSame([$this->emailRegistrar->id, $this->emailRegistrar->id, $this->easyRegistrar->id], [
            Tld::query()->where('tld', '.gr')->value('registrar_id'),
            Tld::query()->where('tld', '.com')->value('registrar_id'),
            Tld::query()->where('tld', '.com.gr')->value('registrar_id'),
        ]);
    }

    public function test_a_new_order_starts_with_the_tld_s_preferred_registrar_and_three_year_prices(): void
    {
        Tld::query()->create(['tld' => '.de', 'registrar_id' => $this->emailRegistrar->id, 'register_1y_cents' => 1000, 'renew_1y_cents' => 1000, 'register_3y_cents' => 3000, 'renew_3y_cents' => 2700]);
        $client = Client::factory()->create();
        $undo = Repeater::fake();

        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $client->id, 'items' => [['type' => 'domain']]])
            ->fillForm(['items.0.domain' => 'acme.gr'])
            ->assertSchemaStateSet(['items.0.registrar_id' => $this->easyRegistrar->id])
            ->fillForm(['items.0.domain' => 'acme.de'])
            ->assertSchemaStateSet(['items.0.registrar_id' => $this->emailRegistrar->id])
            ->assertFormFieldExists('items.0.years', fn ($field) => array_keys($field->getOptions()) === [1, 3])
            ->fillForm(['items.0.years' => 3])
            ->assertSchemaStateSet(['items.0.billing_cycle' => BillingCycle::Triennially, 'items.0.price_cents' => '27.00', 'items.0.first_payment_cents' => '30.00'])
            // The admin can still pick another registrar; the prices stay the TLD's.
            ->fillForm(['items.0.registrar_id' => $this->easyRegistrar->id])
            ->assertSchemaStateSet(['items.0.price_cents' => '27.00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $undo();

        $domain = Order::query()->sole()->services()->sole();
        $this->assertSame(BillingCycle::Triennially, $domain->billing_cycle);
        $this->assertSame(3, $domain->registrationYears());
        $this->assertSame($this->easyRegistrar->id, $domain->domainDetails->registrar_id);
    }
}
