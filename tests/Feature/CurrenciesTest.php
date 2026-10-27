<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use App\Enums\CurrencyFormat;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Tlds\Pages\ManageTlds;
use App\Filament\Resources\Tlds\TldResource;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Registrar;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use App\Reports\IncomeReport;
use App\Support\Currencies;
use App\Support\ExchangeRates;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Settings → Currencies: other currencies than the base EUR, their rates, and clients billed in them. */
class CurrenciesTest extends TestCase
{
    use RefreshDatabase;

    private const ECB = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
            <Cube>
                <Cube time='2026-10-15'>
                    <Cube currency='USD' rate='1.2000'/>
                    <Cube currency='GBP' rate='0.8000'/>
                </Cube>
            </Cube>
        </gesmes:Envelope>
        XML;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->product = Product::factory()->create([
            'name' => 'Hosting Business',
            'type' => ServiceType::Hosting,
            'billing_cycle' => BillingCycle::Annually,
            'prices' => ['annually' => ['enabled' => true, 'price_cents' => 10000, 'setup_cents' => 1000]],
        ]);
    }

    private function usd(array $attributes = []): Currency
    {
        return Currency::create(['code' => 'usd', 'prefix' => '$', 'suffix' => 'USD', 'format' => CurrencyFormat::CommaThousands, 'rate' => 1.5, ...$attributes]);
    }

    public function test_the_base_currency_is_there_and_new_clients_take_it(): void
    {
        $base = Currencies::get('EUR');

        $this->assertNotNull($base);
        $this->assertTrue($base->isBase());
        $this->assertSame(1.0, Currencies::rate('EUR'));
        $this->assertFalse(Currencies::multiple());
        $this->assertSame('EUR', Client::factory()->create()->currency);
    }

    public function test_amounts_are_written_as_the_currency_says(): void
    {
        $this->usd();
        Currency::create(['code' => 'GBP', 'prefix' => '£', 'format' => CurrencyFormat::DotThousands, 'rate' => 0.9]);

        $this->assertSame('€1,234.56', Money::format(123456)); // base, by language
        $this->assertSame("\$1,234.56\u{00A0}USD", Money::format(123456, 'USD'));
        $this->assertSame('£1.234,56', Money::format(123456, 'GBP'));
        $this->assertSame('-£12,00', Money::format(-1200, 'GBP'));
    }

    public function test_prices_in_another_currency_follow_the_rate_unless_set(): void
    {
        $this->usd();

        $this->assertSame(10000, $this->product->priceFor(BillingCycle::Annually));
        $this->assertSame(15000, $this->product->priceFor(BillingCycle::Annually, 'USD'));
        $this->assertSame(1500, $this->product->setupFeeFor(BillingCycle::Annually, 'USD'));

        $this->product->update(['currency_prices' => ['USD' => ['annually' => ['price_cents' => 14900, 'setup_cents' => null]]]]);
        $this->assertSame(14900, $this->product->priceFor(BillingCycle::Annually, 'USD'));
        $this->assertSame(1500, $this->product->setupFeeFor(BillingCycle::Annually, 'USD'));

        $tld = Tld::query()->create(['tld' => '.com', 'register_1y_cents' => 1000, 'renew_1y_cents' => 1200, 'grace_fee_cents' => 2000, 'currency_prices' => ['USD' => ['renew_1y_cents' => 1999]]]);
        $this->assertSame(1500, $tld->priceCents('register', 1, 'USD'));
        $this->assertSame(1999, $tld->priceCents('renew', 1, 'USD'));
        $this->assertNull($tld->priceCents('transfer', 1, 'USD'));
        $this->assertSame(['grace' => 3000], $tld->lateFees(today()->subDays(3), today(), 'USD'));
    }

    public function test_products_and_tlds_take_prices_in_other_currencies(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake();
        $this->usd();
        $tld = Tld::query()->create(['tld' => '.com', 'register_1y_cents' => 1000]);

        Livewire::test(EditProduct::class, ['record' => $this->product->getRouteKey()])
            ->assertSee('Prices in other currencies')
            ->fillForm(['currency_prices.USD.annually.price_cents' => '149,90'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(14990, $this->product->refresh()->priceFor(BillingCycle::Annually, 'USD'));
        $this->assertSame(1500, $this->product->setupFeeFor(BillingCycle::Annually, 'USD'));

        Livewire::test(ManageTlds::class)
            ->callAction(TestAction::make('edit')->table($tld), ['currency_prices.USD.register_1y_cents' => '12'])
            ->assertHasNoFormErrors();

        $this->assertSame(1200, $tld->refresh()->priceCents('register', 1, 'USD'));
    }

    public function test_the_tld_price_tables_fit_a_phone(): void
    {
        $table = (new \ReflectionMethod(TldResource::class, 'priceTable'))
            ->invoke(null, 'USD', fn (string $column) => TextInput::make($column));

        // Three prices a row on a phone, the currency and each term label on a row of their own; four columns from sm up.
        $this->assertSame(['default' => 3, 'sm' => 4], array_filter($table->getColumns()));
        $this->assertSame(['default' => 3, 'sm' => 1], $table->getDefaultChildComponents()[0]->getColumnSpan());
    }

    public function test_update_product_prices_sets_them_all_at_todays_rates(): void
    {
        $this->actingAs(User::factory()->create());
        $usd = $this->usd();
        $this->product->update(['currency_prices' => ['USD' => ['annually' => ['price_cents' => 1]]]]);
        $tld = Tld::query()->create(['tld' => '.com', 'register_1y_cents' => 1000]);
        $usd->update(['rate' => 2]);

        Livewire::test(ManageCurrencies::class)
            ->callAction('updatePrices')
            ->assertNotified();

        $this->assertSame(20000, $this->product->refresh()->currency_prices['USD']['annually']['price_cents']);
        $this->assertSame(2000, $this->product->currency_prices['USD']['annually']['setup_cents']);
        $this->assertSame(2000, $tld->refresh()->currency_prices['USD']['register_1y_cents']);
        $this->assertTrue(ActivityLog::query()->where('event', 'currency.prices_updated')->exists());
    }

    public function test_exchange_rates_come_from_the_ecb(): void
    {
        $this->usd();
        Currency::create(['code' => 'CHF', 'rate' => 1]);
        Http::fake([ExchangeRates::ECB_URL => Http::response(self::ECB)]);

        $result = app(ExchangeRates::class)->update();

        $this->assertSame(['USD' => '1.20000'], $result['updated']);
        $this->assertSame(['CHF'], $result['missing']);
        $this->assertSame(1.2, Currencies::rate('USD'));
        $this->assertTrue(ActivityLog::query()->where('event', 'currency.rates_updated')->exists());
    }

    public function test_exchange_rates_for_another_base_currency_are_crossed_through_the_euro(): void
    {
        config(['gaiacp.currency' => 'GBP']);
        Currency::create(['code' => 'GBP', 'rate' => 1]);
        $this->usd();
        Http::fake([ExchangeRates::ECB_URL => Http::response(self::ECB)]);

        $result = app(ExchangeRates::class)->update();

        $this->assertSame('1.25000', Currency::query()->where('code', 'EUR')->value('rate')); // 1 / 0.8
        $this->assertSame('1.50000', $result['updated']['USD']); // 1.2 / 0.8
    }

    public function test_a_failed_rate_read_changes_nothing(): void
    {
        $this->usd();
        Http::fake([ExchangeRates::ECB_URL => Http::response('down', 503)]);

        $this->expectException(RuntimeException::class);

        try {
            app(ExchangeRates::class)->update();
        } finally {
            $this->assertSame(1.5, Currencies::rate('USD'));
        }
    }

    public function test_the_currencies_page_adds_updates_and_deletes(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([ExchangeRates::ECB_URL => Http::response(self::ECB)]);

        $this->get('/admin/settings/currencies')->assertOk()->assertSee('EUR');

        Livewire::test(ManageCurrencies::class)
            ->callAction('create', ['code' => 'usd', 'prefix' => '$', 'format' => '1,234.56', 'rate' => '1.1'])
            ->assertHasNoFormErrors();

        Livewire::test(ManageCurrencies::class)
            ->callAction('create', ['code' => 'USD', 'format' => 'locale', 'rate' => '1'])
            ->assertHasFormErrors(['code' => 'unique']);

        Livewire::test(ManageCurrencies::class)
            ->callAction('updateRates')
            ->assertNotified();

        $usd = Currency::query()->where('code', 'USD')->sole();
        $this->assertSame('1.20000', $usd->rate);

        $client = Client::factory()->create(['currency' => 'USD']);
        Livewire::test(ManageCurrencies::class)
            ->assertActionHidden(TestAction::make('delete')->table($usd))
            ->assertActionHidden(TestAction::make('delete')->table(Currency::query()->where('code', 'EUR')->sole()));

        $client->delete();
        Livewire::test(ManageCurrencies::class)
            ->callAction(TestAction::make('delete')->table($usd));
        $this->assertFalse(Currency::query()->where('code', 'USD')->exists());
    }

    public function test_a_client_keeps_its_currency_once_it_has_services(): void
    {
        $this->actingAs(User::factory()->create());
        $this->usd();
        $client = Client::factory()->create();

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertFormFieldEnabled('currency')
            ->fillForm(['currency' => 'USD'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('USD', $client->refresh()->currency);

        Service::factory()->for($client)->create(['currency' => 'USD']);
        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertFormFieldDisabled('currency');
    }

    public function test_a_client_order_is_priced_and_billed_in_the_clients_currency(): void
    {
        $this->usd();
        Registrar::factory()->withTlds()->create(); // .gr 15.00 a year
        $client = Client::factory()->create(['currency' => 'USD']);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);

        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');
        Livewire::test(OrderPage::class)
            ->fillForm(['product' => (string) $this->product->id, 'billing_cycle' => 'annually', 'domain_option' => ClientOrder::REGISTER, 'domain' => 'shop.gr', 'years' => 1])
            ->assertSee("\$150.00\u{00A0}USD")
            ->checkout()
            ->assertHasNoFormErrors();

        $services = Order::query()->sole()->services()->get()->keyBy(fn (Service $s) => $s->type->value);
        $this->assertSame(['USD'], $services->pluck('currency')->unique()->values()->all());
        $this->assertSame(15000, $services['hosting']->price_cents);
        $this->assertSame(16500, $services['hosting']->first_payment_cents);
        $this->assertSame(2250, $services['domain']->first_payment_cents);
        $this->assertSame('USD', Invoice::query()->sole()->currency);
    }

    public function test_the_admin_order_and_service_forms_price_in_the_clients_currency(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake();
        $this->usd();
        $usdClient = Client::factory()->create(['currency' => 'USD']);
        $eurClient = Client::factory()->create();
        $undo = Repeater::fake();

        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $usdClient->id, 'items' => [['type' => 'hosting']]])
            ->fillForm(['items.0.product_id' => $this->product->id])
            ->assertSchemaStateSet(['items.0.price_cents' => '150.00', 'items.0.first_payment_cents' => '165.00'])
            ->fillForm(['client_id' => $eurClient->id])
            ->assertSchemaStateSet(['items.0.price_cents' => '100.00', 'items.0.first_payment_cents' => '110.00']);

        $undo();

        Livewire::test(CreateService::class)
            ->fillForm(['client_id' => $usdClient->id, 'product_id' => $this->product->id])
            ->assertSchemaStateSet(['currency' => 'USD', 'price_cents' => '150.00']);
    }

    public function test_totals_over_several_currencies_are_in_the_base_one(): void
    {
        $this->usd(['rate' => 2]);
        $client = Client::factory()->create(['currency' => 'USD']);
        $service = Service::factory()->for($client)->create(['currency' => 'USD']);
        Payment::factory()->for($service)->create(['amount_cents' => 4000, 'currency' => 'USD', 'paid_on' => today()]);
        Payment::factory()->for(Service::factory())->create(['amount_cents' => 1000, 'currency' => 'EUR', 'paid_on' => today()]);

        $this->assertSame(3000, Currencies::sumToBase(Payment::query(), 'amount_cents'));
        $this->assertSame(3000, app(IncomeReport::class)->monthly(today()->year)[today()->month]['cents']);
    }

    public function test_the_command_updates_the_rates_and_prices(): void
    {
        $this->artisan('gaiacp:update-exchange-rates')->expectsOutputToContain('Only the base currency')->assertSuccessful();

        $this->usd();
        Http::fake([ExchangeRates::ECB_URL => Http::response(self::ECB)]);

        $this->artisan('gaiacp:update-exchange-rates --prices')->expectsOutputToContain('USD: 1.20000')->assertSuccessful();
        $this->assertSame(12000, $this->product->refresh()->currency_prices['USD']['annually']['price_cents']);
    }
}
