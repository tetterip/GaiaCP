<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Filament\Clusters\Settings\Pages\PaymentMethodsSettings;
use App\Filament\Clusters\Settings\Pages\TaxSettings;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Payments\PaymentMethods;
use App\Plugins\PluginManager;
use App\Support\MailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Plugins\BankTransfer\BankTransferSettings;
use Plugins\GoogleLogin\GoogleLoginSettings;
use Plugins\Stripe\StripeSettings;
use Tests\TestCase;

/** Payment methods come from payment plugins (Bank transfer first) plus the built-in cash and other. */
class PaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private function disableBankTransfer(): void
    {
        $plugins = app(PluginManager::class);
        $plugins->disable($plugins->find('bank_transfer'));
    }

    public function test_bank_transfer_is_a_payment_method_from_its_plugin(): void
    {
        $this->assertSame(['bank_transfer' => 'Bank transfer', 'stripe' => 'Card (Stripe)', 'cash' => 'Cash', 'other' => 'Other'], PaymentMethods::options());
        $this->assertSame('bank_transfer', PaymentMethods::default());
        $this->assertSame('Bank transfer', PaymentMethods::label('bank_transfer'));
    }

    public function test_its_instructions_go_into_payment_notice_emails(): void
    {
        config(['gaiacp.payment_instructions' => "Eurobank\nGR00 0000 0000"]);

        $this->assertSame("Eurobank<br>\nGR00 0000 0000", (string) MailTemplates::common(Client::factory()->make())['payment_instructions']);
    }

    public function test_a_disabled_plugin_is_no_longer_offered_but_old_payments_keep_its_name(): void
    {
        config(['gaiacp.payment_instructions' => 'Eurobank']);
        $payment = Payment::factory()->create(['method' => 'bank_transfer']);

        $this->disableBankTransfer();

        // Card payments are still offered, but never pre-selected: Stripe records its own.
        $this->assertSame(['stripe' => 'Card (Stripe)', 'cash' => 'Cash', 'other' => 'Other'], PaymentMethods::options());
        $this->assertSame('cash', PaymentMethods::default());
        $this->assertSame('', PaymentMethods::instructions());
        $this->assertSame('Bank transfer', PaymentMethods::label($payment->method));
        $this->assertArrayHasKey('bank_transfer', PaymentMethods::options('bank_transfer'));
        $this->assertArrayHasKey('bank_transfer', PaymentMethods::all());
    }

    public function test_the_bank_transfer_page_saves_the_payment_instructions_and_hides_while_off(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BankTransferSettings::class)
            ->fillForm(['payment_instructions' => "Eurobank\nGR00 0000 0000"])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['payment_instructions'], Setting::query()->pluck('key')->all());
        $this->assertSame("Eurobank\nGR00 0000 0000", config('gaiacp.payment_instructions'));

        // Off, the page stays open with its switch, so it can be turned on again.
        $this->disableBankTransfer();
        $this->get(BankTransferSettings::getUrl())->assertOk();
        Livewire::test(BankTransferSettings::class)->assertSet('pluginEnabled', false);
    }

    public function test_settings_lists_the_payment_methods_right_after_company_each_opening_its_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(TaxSettings::getUrl())->assertOk()
            ->assertSeeInOrder([CompanySettings::getUrl(), PaymentMethodsSettings::getUrl(), TaxSettings::getUrl()])
            ->assertDontSee(StripeSettings::getUrl()); // under Payment methods, shown once it is open

        $this->get(PaymentMethodsSettings::getUrl())->assertOk()
            ->assertSeeInOrder(['Payment methods', 'Bank transfer', 'Stripe'])
            ->assertSee(BankTransferSettings::getUrl())
            ->assertSee(StripeSettings::getUrl());

        // On a method's own page the menu keeps the list open under Payment methods.
        $this->get(StripeSettings::getUrl())->assertOk()
            ->assertSeeInOrder([PaymentMethodsSettings::getUrl(), BankTransferSettings::getUrl(), StripeSettings::getUrl(), TaxSettings::getUrl()]);
    }

    public function test_a_method_out_of_reach_opens_nothing(): void
    {
        $this->disableBankTransfer();
        User::factory()->create(); // the first admin is a super admin
        $this->actingAs(User::factory()->limitedTo([AdminArea::BusinessSettings])->create());

        Livewire::test(PaymentMethodsSettings::class)
            ->assertCanSeeTableRecords(['bank_transfer', 'stripe'])
            ->assertSee('Disabled')
            ->assertSee(BankTransferSettings::getUrl()) // off, but its page has the switch
            ->assertDontSee(StripeSettings::getUrl()); // Stripe's keys are for super admins only

        // Only super admins (who open Settings → Plugins) flip a switch.
        Livewire::test(BankTransferSettings::class)->assertFormFieldIsDisabled('pluginEnabled', 'content');

        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients])->create());
        $this->get(PaymentMethodsSettings::getUrl())->assertForbidden();
    }

    public function test_the_switch_on_a_methods_page_turns_its_plugin_off_and_on(): void
    {
        $this->actingAs(User::factory()->create());
        $plugins = app(PluginManager::class);

        $page = Livewire::test(StripeSettings::class)->assertSet('pluginEnabled', true)
            ->set('pluginEnabled', false);
        $this->assertFalse($plugins->enabled('stripe'));
        $this->assertArrayNotHasKey('stripe', PaymentMethods::options());
        $this->assertDatabaseHas('activity_logs', ['event' => 'plugin.disabled']);

        $page->set('pluginEnabled', true);
        $this->assertTrue($plugins->enabled('stripe'));

        Livewire::test(GoogleLoginSettings::class)->set('pluginEnabled', false);
        $this->assertFalse($plugins->enabled('google_login'));
        $this->get(GoogleLoginSettings::getUrl())->assertOk();
    }
}
