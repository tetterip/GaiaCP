<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Payments\PaymentMethods;
use App\Plugins\PluginManager;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Plugins\Iris\Iris;
use Plugins\Iris\IrisPlugin;
use Plugins\Iris\IrisSettings;
use Tests\TestCase;

/** The IRIS plugin: the IRIS number and the bank's QR code on the client's unpaid payment notices, recorded by hand. */
class IrisPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Invoice $notice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake(Iris::DISK);
        config(['gaiacp.iris' => ['payee' => 'Gaia Hosting', 'number' => '6912345678', 'qr' => null, 'note' => null]]);
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('iris'));

        $this->client = Client::factory()->create(['email' => 'maria@example.gr']);
        $due = CarbonImmutable::today()->addDays(10);
        $service = Service::factory()->for($this->client)->create([
            'label' => 'Web hosting maria.gr', 'start_date' => $due->subYear(), 'next_due_date' => $due,
            'billing_cycle' => BillingCycle::Annually, 'price_cents' => 6000,
        ]);
        $this->notice = Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $this->client->id, 'date' => today(), 'due_date' => $due,
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
        $this->notice->items()->create([
            'service_id' => $service->id, 'description' => 'Web hosting maria.gr', 'due_date' => $due, 'period_end' => $due->addYear(),
            'amount_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);
    }

    private function actAsClient(): void
    {
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $this->client->id, 'email' => $this->client->email]);
        $this->actingAs($user, 'client');
        Filament::setCurrentPanel('client');
    }

    private function qr(string $file = 'iris/qr.png'): string
    {
        Storage::disk(Iris::DISK)->put($file, UploadedFile::fake()->image('qr.png', 300, 300)->getContent());
        config(['gaiacp.iris.qr' => $file]);

        return $file;
    }

    public function test_unpaid_notices_get_pay_with_iris_once_there_is_a_number_or_a_qr_code(): void
    {
        $this->actAsClient();
        $page = fn () => Livewire::test(ViewPaymentNotice::class, ['record' => $this->notice->getRouteKey()]);

        $page()->assertActionVisible('payWithIris');
        $this->get("/payment-notices/{$this->notice->id}")->assertSee('Pay with IRIS');

        config(['gaiacp.iris.number' => null]);
        $page()->assertActionDoesNotExist('payWithIris');

        $this->qr(); // the QR code alone is enough
        $page()->assertActionVisible('payWithIris');

        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('iris'));
        $page()->assertActionDoesNotExist('payWithIris');
        $plugins->enable($plugins->find('iris'));

        $this->notice->update(['status' => InvoiceStatus::Paid, 'paid_on' => today()]);
        $page()->assertActionHidden('payWithIris');
    }

    public function test_the_window_shows_the_qr_code_the_details_and_the_notice_number_as_the_reason(): void
    {
        $this->qr();
        config(['gaiacp.iris.note' => 'Works with every Greek bank <b>app</b>']);

        $html = IrisPlugin::details($this->notice)->toHtml();

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('6912345678', $html);
        $this->assertStringContainsString('Gaia Hosting', $html);
        $this->assertStringContainsString($this->notice->number, $html);
        $this->assertStringContainsString('60', $html);
        $this->assertStringContainsString('&lt;b&gt;app&lt;/b&gt;', $html); // the note is escaped
    }

    public function test_the_payee_defaults_to_the_company_name(): void
    {
        config(['gaiacp.iris.payee' => null, 'gaiacp.company.name' => 'My Company']);

        $this->assertSame('My Company', Iris::payee());
        $this->assertStringContainsString('6912345678 (My Company)', (string) Iris::instructions());
    }

    public function test_it_is_a_payment_method_recorded_by_hand_with_its_instructions_on_notices_and_emails(): void
    {
        $this->assertSame('IRIS', PaymentMethods::options()['iris']);
        $this->assertSame('bank_transfer', PaymentMethods::default()); // Bank transfer stays the pre-selected method
        $this->assertStringContainsString('IRIS number: 6912345678 (Gaia Hosting)', PaymentMethods::instructions());
        $this->assertStringContainsString('payment notice number as the reason', PaymentMethods::instructions());

        config(['gaiacp.iris.number' => null]);
        $this->assertStringNotContainsString('IRIS', PaymentMethods::instructions());
    }

    public function test_the_client_guide_mentions_it_only_while_it_is_set_up(): void
    {
        $this->actAsClient();
        $this->get('/help')->assertOk()->assertSee('Pay with IRIS');

        config(['gaiacp.iris.number' => null]);
        $this->get('/help')->assertOk()->assertDontSee('Pay with IRIS');
    }

    public function test_the_settings_page_stores_the_details_and_a_new_qr_code_replaces_the_old_file(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(IrisSettings::class)
            ->fillForm(['iris' => ['payee' => 'Gaia', 'number' => 'gaia-alias', 'qr' => UploadedFile::fake()->image('qr.png', 300, 300)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $first = config('gaiacp.iris.qr');
        $this->assertStringStartsWith('iris/', $first);
        Storage::disk(Iris::DISK)->assertExists($first);
        $this->assertSame('gaia-alias', Setting::query()->find('iris.number')->value);
        $this->assertSame('Gaia', config('gaiacp.iris.payee'));

        Livewire::test(IrisSettings::class)
            ->fillForm(['iris' => ['qr' => [UploadedFile::fake()->image('new.png', 300, 300)]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame($first, config('gaiacp.iris.qr'));
        Storage::disk(Iris::DISK)->assertMissing($first);
        Storage::disk(Iris::DISK)->assertExists(config('gaiacp.iris.qr'));
    }

    public function test_the_qr_code_must_be_a_png_jpg_or_webp_image(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(IrisSettings::class)
            ->fillForm(['iris' => ['qr' => UploadedFile::fake()->create('qr.svg', 10, 'image/svg+xml')]])
            ->call('save')
            ->assertHasFormErrors(['iris.qr']);
    }
}
