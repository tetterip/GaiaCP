<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\Register;
use App\Filament\Client\Pages\Cart;
use App\Filament\Client\Pages\Help;
use App\Filament\Client\Pages\Order as OrderPage;
use App\Filament\Clusters\Settings\Pages\ClientAreaSettings;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\TermsOfService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Client area → Terms of service: accepted on client orders and sign up, recorded with time and version. */
class TermsOfServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $client = Client::factory()->create();
        $this->user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->product = Product::factory()->create(['name' => 'Support plan', 'type' => ServiceType::Other]);
    }

    private function terms(array $settings = []): void
    {
        config(['gaiacp.client_area.terms' => true, 'gaiacp.client_area.terms_text' => "# Our terms\n\nBe **nice**.", 'gaiacp.client_area.terms_version' => '2.0', ...$settings]);
    }

    private function orderPage(): Testable
    {
        Filament::setCurrentPanel('client');
        $this->actingAs($this->user, 'client');

        return Livewire::test(OrderPage::class)->fillForm(['product' => (string) $this->product->id, 'billing_cycle' => 'annually']);
    }

    /** The cart with the product in it, where the terms are accepted. */
    private function cartPage(): Testable
    {
        $this->orderPage()->call('addToCart')->assertHasNoFormErrors();

        return Livewire::test(Cart::class);
    }

    public function test_off_by_default_orders_ask_nothing_and_record_nothing(): void
    {
        $this->assertFalse(TermsOfService::required());

        $this->orderPage()
            ->assertDontSee('I accept the Terms of Service')
            ->checkout()
            ->assertHasNoFormErrors();

        $order = Order::query()->sole();
        $this->assertNull($order->terms_accepted_at);
        $this->assertNull($order->terms_version);

        // Switched on with neither a link nor a text, there is nothing to accept.
        config(['gaiacp.client_area.terms' => true]);
        $this->assertFalse(TermsOfService::required());
    }

    public function test_an_order_needs_the_terms_accepted_and_keeps_when_and_which_version(): void
    {
        $this->terms();
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 40));

        $page = $this->orderPage()->checkout()
            ->assertSee('I accept the Terms of Service')
            ->assertSee('Read the Terms of Service')
            ->assertHasFormErrors(['accept_terms']);
        $this->assertSame(0, Order::count());

        $page->fillForm(['accept_terms' => true])->call('placeOrder')->assertHasNoFormErrors();

        $order = Order::query()->sole();
        $this->assertTrue($order->terms_accepted_at->eq(now()));
        $this->assertSame('2.0', $order->terms_version);
        $this->assertDatabaseHas('activity_logs', ['event' => 'order.client_placed']);

        // The admin sees it on the order.
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertSee('Accepted on 17/10/2026 12:40, version 2.0');
    }

    public function test_the_terms_open_from_the_checkbox(): void
    {
        // Their text in a window, as Markdown with any HTML stripped...
        $this->terms(['gaiacp.client_area.terms_text' => "# Our terms\n\nBe **nice**.<script>alert(1)</script>"]);
        $this->cartPage()->mountAction(TestAction::make('readTerms')->schemaComponent('accept_terms', 'form'))->assertHasNoErrors();
        $html = (string) TermsOfService::html();
        $this->assertStringContainsString('<h1>Our terms</h1>', $html);
        $this->assertStringContainsString('<strong>nice</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);

        // ...or their page in a new tab.
        $this->terms(['gaiacp.client_area.terms_text' => null, 'gaiacp.client_area.terms_url' => 'https://example.com/terms']);
        $this->cartPage()->assertSeeHtml('href="https://example.com/terms"');
    }

    public function test_sign_up_needs_the_terms_accepted_and_the_client_keeps_it(): void
    {
        $this->terms(['gaiacp.client_area.signup' => true]);
        Filament::setCurrentPanel('client');

        $details = [
            'first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr', 'phone' => '+30 6912345678',
            'address1' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'GR',
            'password' => 'Welcome2026x', 'passwordConfirmation' => 'Welcome2026x',
        ];

        Livewire::test(Register::class)->assertSee('I accept the Terms of Service')->fillForm($details)->call('register')->assertHasFormErrors(['accept_terms']);
        $this->assertNull(Client::query()->where('email', 'maria@example.gr')->first());

        Livewire::test(Register::class)->fillForm($details + ['accept_terms' => true])->call('register')->assertHasNoFormErrors();

        $client = Client::query()->where('email', 'maria@example.gr')->sole();
        $this->assertNotNull($client->terms_accepted_at);
        $this->assertSame('2.0', $client->terms_version);

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);
        Livewire::test(EditClient::class, ['record' => $client->id])->assertSee('Accepted the terms of service when signing up on');
    }

    public function test_the_version_follows_the_terms_unless_the_admin_writes_one(): void
    {
        $this->actingAs($this->admin);
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(9, 5));

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area' => ['terms' => true]])
            ->call('save')
            ->assertHasFormErrors(['client_area.terms']);

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area' => ['terms' => true, 'terms_text' => 'Version one']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('17/10/2026 09:05', config('gaiacp.client_area.terms_version'));
        $this->assertTrue(TermsOfService::required());

        // Changed terms get a new version by themselves...
        $this->travel(2)->days();
        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area' => ['terms' => true, 'terms_text' => 'Version two']])
            ->call('save');
        $this->assertSame('19/10/2026 09:05', config('gaiacp.client_area.terms_version'));

        // ...saving other settings keeps it, and a version the admin writes wins.
        $this->travel(1)->days();
        Livewire::test(ClientAreaSettings::class)->call('save');
        $this->assertSame('19/10/2026 09:05', config('gaiacp.client_area.terms_version'));

        Livewire::test(ClientAreaSettings::class)
            ->fillForm(['client_area' => ['terms' => true, 'terms_text' => 'Version three', 'terms_version' => '3.0']])
            ->call('save');
        $this->assertSame('3.0', config('gaiacp.client_area.terms_version'));
        $this->assertTrue(Help::features()['terms']); // the client area guide mentions them
    }
}
