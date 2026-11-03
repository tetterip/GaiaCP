<?php

namespace Tests\Feature\Registrar;

use App\Actions\ImportTldPrices;
use App\Filament\Resources\Tlds\Pages\ManageTlds;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Registrar;
use App\Models\Tld;
use App\Models\User;
use App\Provisioning\ProvisioningException;
use App\Support\Currencies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\FakesEasy;
use Tests\FakesEnom;
use Tests\TestCase;

class TldImportTest extends TestCase
{
    use FakesEasy;
    use FakesEnom;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function easyPrices(): void
    {
        $this->fakeEasy();
        $this->easyTldPrices = [
            ['tld' => 'gr', 'min_period' => '2', 'min_buy' => '20.00', 'min_ren' => '18.50', 'transfer' => '0', 'redemption' => '60'],
            ['tld' => 'com', 'min_period' => '1', 'min_buy' => '9.90', 'min_ren' => '11.20', 'transfer' => '9.90', 'redemption' => ''],
            ['tld' => 'eu', 'min_period' => 1, 'min_buy' => 6.5, 'min_ren' => 6.5, 'transfer' => 6.5, 'redemption' => 40],
            ['tld' => 'xyz', 'min_period' => '10', 'min_buy' => '99', 'min_ren' => '99', 'transfer' => '99'],
        ];
    }

    public function test_easy_gr_lists_its_tld_costs_for_their_shortest_term(): void
    {
        $this->easyPrices();

        $rows = app(ImportTldPrices::class)->fetch($this->easyRegistrar);

        $this->assertSame(['.com', '.eu', '.gr'], array_keys($rows)); // a 10-year term is not one GaiaCP prices
        $this->assertSame(['tld' => '.gr', 'years' => 2, 'register' => 2000, 'renew' => 1850, 'transfer' => null, 'redemption' => 6000], $rows['.gr']);
        $this->assertSame(990, $rows['.com']['register']);
        $this->assertContains(33, array_column($this->easyCalls, 0));
    }

    public function test_selling_price_adds_the_markup_and_rounds_up(): void
    {
        $this->assertSame(1200, ImportTldPrices::sellingPrice(1000, 20, 0, 'none'));
        $this->assertSame(1450, ImportTldPrices::sellingPrice(1000, 20, 250, 'none'));
        $this->assertSame(1200, ImportTldPrices::sellingPrice(1188, 0, 0, 'whole'));
        $this->assertSame(1300, ImportTldPrices::sellingPrice(1201, 0, 0, 'whole'));
        $this->assertSame(1200, ImportTldPrices::sellingPrice(1200, 0, 0, 'whole'));
        $this->assertSame(1299, ImportTldPrices::sellingPrice(1230, 0, 0, '99'));
        $this->assertSame(1299, ImportTldPrices::sellingPrice(1299, 0, 0, '99'));
        $this->assertSame(1399, ImportTldPrices::sellingPrice(1300, 0, 0, '99'));
        $this->assertSame(1188, ImportTldPrices::sellingPrice(990, 20, 0, 'none'));
    }

    public function test_import_creates_new_tlds_with_prices_and_updates_only_the_cost_of_listed_ones(): void
    {
        $this->easyPrices();
        $import = app(ImportTldPrices::class);
        $rows = array_values($import->fetch($this->easyRegistrar));

        $counts = $import->apply($this->easyRegistrar, $rows, ['percent' => 20, 'fixed_cents' => 0, 'rounding' => '99', 'prices' => false, 'active' => false]);

        $this->assertSame(['created' => 1, 'updated' => 2], $counts);

        $eu = Tld::query()->where('tld', '.eu')->sole();
        $this->assertFalse($eu->active);
        $this->assertSame($this->easyRegistrar->id, $eu->registrar_id);
        $this->assertSame(799, $eu->register_1y_cents); // 6.50 + 20% = 7.80 → 7.99
        $this->assertSame(650, $eu->costCents('renew', 1));
        $this->assertSame(4899, $eu->redemption_fee_cents);
        $this->assertNull($eu->register_2y_cents);

        $gr = Tld::query()->where('tld', '.gr')->sole();
        $this->assertSame(1500, $gr->register_1y_cents); // its own prices stay
        $this->assertNull($gr->register_2y_cents);
        $this->assertSame(2000, $gr->costCents('register', 2));
        $this->assertSame(1850, $gr->costCents('renew', 2));
        $this->assertNull($gr->costCents('transfer', 2));

        $this->assertDatabaseHas('activity_logs', ['event' => 'tld.imported']);
    }

    public function test_import_can_set_the_prices_and_the_preferred_registrar_of_listed_tlds(): void
    {
        $this->easyPrices();
        $other = Registrar::factory()->create(['name' => 'Other']);
        Tld::query()->where('tld', '.com')->update(['registrar_id' => $other->id]);
        $import = app(ImportTldPrices::class);

        $import->apply($this->easyRegistrar, [$import->fetch($this->easyRegistrar)['.com']], ['percent' => 0, 'fixed_cents' => 300, 'rounding' => 'whole', 'prices' => true, 'preferred' => true]);

        $com = Tld::query()->where('tld', '.com')->sole();
        $this->assertSame(1300, $com->register_1y_cents); // 9.90 + 3.00 → 13
        $this->assertSame(1500, $com->renew_1y_cents); // 11.20 + 3.00 → 15
        $this->assertSame($this->easyRegistrar->id, $com->registrar_id);
    }

    public function test_enom_is_asked_about_each_tld_and_its_usd_costs_are_converted(): void
    {
        $this->fakeEnom();
        Currency::query()->create(['code' => 'USD', 'rate' => 1.25]);
        Currencies::flush();
        $this->enomPrices = ['com' => ['register' => '10.00', 'renew' => '12.50', 'transfer' => '10.00'], 'net' => ['register' => '15.00']];

        $rows = app(ImportTldPrices::class)->fetch($this->enomRegistrar); // its own TLDs: .com and .net

        $this->assertSame(['tld' => '.com', 'years' => 1, 'register' => 800, 'renew' => 1000, 'transfer' => 800, 'redemption' => null], $rows['.com']);
        $this->assertSame(1200, $rows['.net']['register']);
        $this->assertNull($rows['.net']['renew']);
        $this->assertSame(6, collect($this->enomCalls)->where(0, 'PE_GetResellerPrice')->count());
    }

    public function test_a_currency_without_a_rate_is_refused(): void
    {
        $this->fakeEnom();
        $this->enomPrices = ['com' => ['register' => '10.00']];

        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage('add USD under Settings → Currencies');

        app(ImportTldPrices::class)->fetch($this->enomRegistrar, ['.com']);
    }

    public function test_daily_sync_updates_costs_only_and_rings_once_a_tld_sells_at_a_loss(): void
    {
        $this->easyPrices();
        $this->easyTldPrices[1]['min_ren'] = '16.00'; // .com renews for 15.00 here
        config(['gaiacp.domains.sync_costs' => true]);

        $this->artisan('gaiacp:sync-tld-costs')->assertSuccessful();

        $com = Tld::query()->where('tld', '.com')->sole();
        $this->assertSame(1600, $com->costCents('renew', 1));
        $this->assertSame(1500, $com->renew_1y_cents);
        $this->assertNull(Tld::query()->where('tld', '.eu')->first()); // never adds TLDs
        $this->assertSame(['.com'], ActivityLog::query()->where('event', 'tld.selling_at_loss')->sole()->properties['tlds']);

        // Nothing new the next night: no second bell.
        $this->artisan('gaiacp:sync-tld-costs')->assertSuccessful();
        $this->assertSame(1, ActivityLog::query()->where('event', 'tld.selling_at_loss')->count());
    }

    public function test_daily_sync_is_off_by_default(): void
    {
        $this->easyPrices();

        $this->artisan('gaiacp:sync-tld-costs')->assertSuccessful();

        $this->assertNotContains(33, array_column($this->easyCalls, 0));
    }

    public function test_import_window_on_domain_pricing(): void
    {
        $this->easyPrices();
        Cache::flush();

        Livewire::test(ManageTlds::class)
            ->assertActionVisible('importTldPrices')
            ->mountAction('importTldPrices')
            ->setActionData(['registrar_id' => $this->easyRegistrar->id, 'only' => [], 'percent' => 10, 'fixed' => '0', 'rounding' => 'none', 'prices' => 0, 'preferred' => false, 'active' => true, 'tlds' => ['.eu']])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $eu = Tld::query()->where('tld', '.eu')->sole();
        $this->assertTrue($eu->active);
        $this->assertSame(715, $eu->register_1y_cents);
    }

    public function test_import_is_hidden_without_a_registrar_that_lists_prices(): void
    {
        Livewire::test(ManageTlds::class)->assertActionHidden('importTldPrices');
    }
}
