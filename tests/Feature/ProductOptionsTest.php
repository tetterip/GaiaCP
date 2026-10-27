<?php

namespace Tests\Feature;

use App\Actions\ClientOrder;
use App\Enums\BillingCycle;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Cart;
use App\Filament\Client\Pages\Order as ClientOrderPage;
use App\Filament\Client\Resources\Services\Pages\ViewService as ClientViewService;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\ProductOptionGroups\Pages\CreateProductOptionGroup;
use App\Filament\Resources\Services\Pages\EditService;
use App\Invoices\GenerateInvoices;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\Service;
use App\Models\User;
use App\Support\ProductOptions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Product options: prices per cycle, New order, the service form and payment notices. */
class ProductOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductOptionGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create(['name' => 'VPS', 'type' => ServiceType::Other, 'package' => null]);
        $this->group = ProductOptionGroup::create(['name' => 'VPS options', 'options' => [
            ['name' => 'Location', 'type' => 'select', 'choices' => [
                ['name' => 'Athens', 'prices' => ['monthly' => 0]],
                ['name' => 'Frankfurt', 'prices' => ['monthly' => 100, 'annually' => 1000], 'setup_cents' => 500],
            ]],
            ['name' => 'Extra IPs', 'type' => 'quantity', 'min' => 0, 'max' => 4, 'choices' => [['prices' => ['monthly' => 200]]]],
            ['name' => 'Daily backup', 'type' => 'yesno', 'choices' => [['prices' => ['monthly' => 50]]]],
            ['name' => 'Managed', 'type' => 'yesno', 'hidden' => true, 'choices' => [['prices' => ['monthly' => 300]]]],
        ]]);
        $this->group->products()->attach($this->product);
    }

    /** @return array<string, string> option name => id */
    private function ids(): array
    {
        return collect($this->group->fresh()->options)->pluck('id', 'name')->all();
    }

    /** @return array<string, string> choice name => id of a list option */
    private function choices(string $option): array
    {
        return collect(collect($this->group->fresh()->options)->firstWhere('name', $option)['choices'])->pluck('id', 'name')->all();
    }

    public function test_picks_are_priced_per_cycle_and_kept_with_their_price(): void
    {
        $ids = $this->ids();
        $this->assertCount(4, array_filter($ids), 'every option gets an id on save');

        $values = [$ids['Location'] => $this->choices('Location')['Frankfurt'], $ids['Extra IPs'] => '9', $ids['Daily backup'] => true];

        $annual = ProductOptions::select($this->product, $values, BillingCycle::Annually);
        $this->assertSame(['Location', 'Extra IPs', 'Daily backup'], array_column($annual, 'name'), 'yes/no left off is not kept');
        // Its own annual price; others the monthly one times 12; a quantity at most its maximum.
        $this->assertSame([1000, 4 * 2400, 600], array_column($annual, 'price_cents'));
        $this->assertSame(1000 + 9600 + 600, ProductOptions::total($annual));
        $this->assertSame(500, ProductOptions::setupTotal($annual));

        $quarterly = ProductOptions::select($this->product, $values, BillingCycle::Quarterly);
        $this->assertSame([300, 2400, 150], array_column($quarterly, 'price_cents'));

        // A pick left as it was keeps its price then, even when the catalog changes.
        $kept = $annual;
        $kept[0]['price_cents'] = 800;
        $this->assertSame(800, ProductOptions::select($this->product, $values, BillingCycle::Annually, null, $kept)[0]['price_cents']);

        Currency::create(['code' => 'USD', 'prefix' => '$', 'rate' => 2]);
        $this->assertSame(2000, ProductOptions::select($this->product, $values, BillingCycle::Annually, 'USD')[0]['price_cents']);
    }

    public function test_new_order_adds_the_options_to_the_price_and_the_notice_names_them(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $ids = $this->ids();
        $undo = Repeater::fake();

        Livewire::test(CreateOrder::class)
            ->fillForm(['client_id' => $client->id, 'items' => [['type' => 'other']]])
            ->fillForm(['items.0.product_id' => $this->product->id])
            ->assertSchemaStateSet(['items.0.price_cents' => '60.00', "items.0.options.{$ids['Location']}" => $this->choices('Location')['Athens']])
            ->fillForm(["items.0.options.{$ids['Location']}" => $this->choices('Location')['Frankfurt']])
            ->assertSchemaStateSet(['items.0.price_cents' => '70.00', 'items.0.first_payment_cents' => '75.00'])
            ->fillForm(["items.0.options.{$ids['Extra IPs']}" => '2', "items.0.options.{$ids['Managed']}" => true])
            ->assertSchemaStateSet(['items.0.price_cents' => '154.00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $undo();

        $service = Order::query()->sole()->services()->sole();
        $this->assertSame(15400, $service->price_cents);
        $this->assertSame(['Location', 'Extra IPs', 'Managed'], array_column($service->options, 'name'));

        $line = Invoice::query()->where('order_id', $service->order_id)->sole()->items()->sole();
        $this->assertSame(15900, $line->amount_cents);
        $this->assertStringContainsString('Location: Frankfurt, Extra IPs: 2', $line->description);
        $this->assertStringNotContainsString('Managed', $line->description, 'hidden from clients');
        $this->assertSame($line->description, GenerateInvoices::describe($service));
    }

    public function test_another_pick_on_the_service_changes_its_price_by_the_difference(): void
    {
        $this->actingAs(User::factory()->create());
        $ids = $this->ids();
        $service = $this->product->services()->create([
            'client_id' => Client::factory()->create()->id, 'type' => ServiceType::Other, 'label' => 'VPS', 'price_cents' => 5000,
            'billing_cycle' => BillingCycle::Annually, 'currency' => 'EUR', 'start_date' => today(), 'next_due_date' => today(), 'status' => 'active',
            // A discounted price (50.00) and a location kept at its price then (8.00).
            'options' => ProductOptions::select($this->product, [$ids['Location'] => $this->choices('Location')['Frankfurt']], BillingCycle::Annually),
        ]);
        $service->update(['options' => [array_replace($service->options[0], ['price_cents' => 800])]]);

        Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertSchemaStateSet(["options.{$ids['Location']}" => $this->choices('Location')['Frankfurt'], "options.{$ids['Daily backup']}" => false])
            ->fillForm(["options.{$ids['Daily backup']}" => true])
            ->assertSchemaStateSet(['price_cents' => '56.00'])
            ->fillForm(["options.{$ids['Location']}" => $this->choices('Location')['Athens']])
            ->assertSchemaStateSet(['price_cents' => '48.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $service->refresh();
        $this->assertSame(4800, $service->price_cents);
        $this->assertSame(['Athens', 'Yes'], array_column($service->options, 'value'));
    }

    public function test_the_admin_sets_up_a_group(): void
    {
        $this->actingAs(User::factory()->create());
        $undo = Repeater::fake();

        Livewire::test(CreateProductOptionGroup::class)
            ->fillForm([
                'name' => 'Hosting extras',
                'products' => [$this->product->id],
                'options' => [['name' => 'SSL', 'type' => 'yesno', 'choices' => [['prices' => ['monthly' => '1.50'], 'setup_cents' => '5']]]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undo();

        $group = ProductOptionGroup::query()->where('name', 'Hosting extras')->sole();
        $this->assertSame(150, $group->options[0]['choices'][0]['prices']['monthly']);
        $this->assertSame(500, $group->options[0]['choices'][0]['setup_cents']);
        $this->assertNotEmpty($group->options[0]['choices'][0]['id']);
        $this->assertContains('SSL', array_column(ProductOptions::for($this->product), 'name'));
    }

    private function clientPage(Client $client, string $page = ClientOrderPage::class, array $params = []): Testable
    {
        Filament::setCurrentPanel('client');
        $user = User::query()->where('client_id', $client->id)->first()
            ?? User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->actingAs($user, 'client');

        return Livewire::test($page, $params);
    }

    public function test_a_client_picks_the_options_shown_to_clients_when_ordering(): void
    {
        Mail::fake();
        $ids = $this->ids();

        $this->clientPage($client = Client::factory()->create())
            ->fillForm(['product' => (string) $this->product->id])
            ->assertSchemaStateSet(["options.{$ids['Location']}" => $this->choices('Location')['Athens'], 'billing_cycle' => 'annually'])
            ->assertSee('Extra IPs')
            ->assertDontSee('Managed')
            ->fillForm(["options.{$ids['Location']}" => $this->choices('Location')['Frankfurt'], "options.{$ids['Extra IPs']}" => '2'])
            // 60.00 + 10.00 + 2 × 24.00, plus the 5.00 setup fee
            ->assertSee('First payment: €123.00')
            ->checkout()
            ->assertHasNoFormErrors();

        $service = Service::query()->where('client_id', $client->id)->sole();
        $this->assertSame(11800, $service->price_cents);
        $this->assertSame(12300, $service->first_payment_cents);
        $this->assertSame(['Location', 'Extra IPs'], array_column($service->options, 'name'), 'a hidden option is never picked for the client');

        $this->clientPage($client, ClientViewService::class, ['record' => $service->getRouteKey()])
            ->assertSee('Location: Frankfurt')
            ->assertSee('Extra IPs: 2');
    }

    public function test_a_choice_the_option_does_not_have_is_refused(): void
    {
        $ids = $this->ids();

        $this->clientPage(Client::factory()->create())
            ->fillForm(['product' => (string) $this->product->id, "options.{$ids['Location']}" => 'made-up'])
            ->checkout()
            ->assertHasErrors();

        $this->assertSame(0, Service::query()->count());
    }

    public function test_the_cart_keeps_the_picks(): void
    {
        $ids = $this->ids();
        $frankfurt = $this->choices('Location')['Frankfurt'];
        app(ClientOrder::class)->addToCart(['product' => (string) $this->product->id, 'billing_cycle' => 'annually', 'options' => [$ids['Location'] => $frankfurt]]);

        $this->clientPage(Client::factory()->create(), Cart::class)
            ->assertSee('€75.00')
            ->call('placeOrder')
            ->assertHasNoFormErrors();

        $this->assertSame('Frankfurt', Service::query()->sole()->options[0]['value']);
    }
}
