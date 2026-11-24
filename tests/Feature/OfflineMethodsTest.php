<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Payments\PaymentMethods;
use App\Plugins\PluginManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Plugins\OfflineMethods\OfflineMethodsPlugin;
use Plugins\OfflineMethods\OfflineMethodsSettings;
use Tests\TestCase;

/** Other ways to pay: payment methods of your own, with instructions on the payment notices. */
class OfflineMethodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $plugins = app(PluginManager::class);
        $plugins->enable($plugins->find('offline-methods'));
    }

    private function save(array $methods): void
    {
        Livewire::test(OfflineMethodsSettings::class)
            ->fillForm(['offline_methods' => ['methods' => $methods]])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_it_starts_off_and_shows_no_method_until_one_is_added(): void
    {
        $plugins = app(PluginManager::class);
        $this->assertTrue($plugins->bundled($plugins->find('offline-methods')));
        $this->assertFalse($plugins->onByDefault($plugins->find('offline-methods')));
        $this->assertArrayNotHasKey('offline_cheque', PaymentMethods::options());
    }

    public function test_each_method_gets_a_key_that_stays_when_it_is_renamed(): void
    {
        $this->save([
            ['name' => 'Cash at our office', 'instructions' => 'Mon–Fri 9:00–17:00', 'active' => true],
            ['name' => 'Revolut', 'instructions' => 'Send {amount} to @gaiahost with {number}', 'active' => true],
            ['name' => 'Revolut', 'instructions' => null, 'active' => false],
        ]);

        $this->assertSame(['offline_cash_at_our_office', 'offline_revolut', 'offline_revolut_2'], array_column(OfflineMethodsPlugin::methods(), 'key'));
        $this->assertSame('Revolut', PaymentMethods::options()['offline_revolut']);
        $this->assertArrayNotHasKey('offline_revolut_2', PaymentMethods::options()); // switched off

        $methods = OfflineMethodsPlugin::methods();
        $methods[0]['name'] = 'Cash';
        $this->save($methods);

        $this->assertSame('offline_cash_at_our_office', OfflineMethodsPlugin::methods()[0]['key']);
        $this->assertSame('Cash', PaymentMethods::label('offline_cash_at_our_office'));
    }

    public function test_instructions_show_with_a_notices_number_and_amount(): void
    {
        $this->save([['name' => 'Revolut', 'instructions' => 'Send {amount} to @gaiahost, reference {number}', 'active' => true]]);
        $notice = Invoice::create([
            'number' => 'PF-2026-0042', 'client_id' => Client::factory()->create()->id, 'date' => today(), 'due_date' => today(),
            'currency' => 'EUR', 'subtotal_cents' => 6000, 'tax_cents' => 0, 'total_cents' => 6000,
        ]);

        $this->assertStringContainsString("Revolut:\nSend €60.00 to @gaiahost, reference PF-2026-0042", PaymentMethods::instructions($notice));
        $this->assertStringContainsString("Revolut:\nSend  to @gaiahost, reference", PaymentMethods::instructions());

        // Off: nothing on the notices
        app(PluginManager::class)->disable(app(PluginManager::class)->find('offline-methods'));
        $this->assertStringNotContainsString('Revolut', PaymentMethods::instructions($notice));
    }

    public function test_a_method_payments_were_recorded_with_can_be_switched_off_but_not_removed(): void
    {
        $this->save([['name' => 'Cheque', 'instructions' => null, 'active' => true]]);
        Payment::factory()->for(Service::factory()->create())->create(['method' => 'offline_cheque']);

        $page = Livewire::test(OfflineMethodsSettings::class);
        $item = array_key_first($page->get('data')['offline_methods']['methods']);

        $page->assertActionHidden(TestAction::make('delete')->schemaComponent('offline_methods.methods', 'form')->arguments(['item' => $item]));

        // One never used can go
        Payment::query()->delete();
        $page->assertActionVisible(TestAction::make('delete')->schemaComponent('offline_methods.methods', 'form')->arguments(['item' => $item]));
    }
}
