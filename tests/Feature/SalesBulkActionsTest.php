<?php

namespace Tests\Feature;

use App\Actions\PlaceOrder;
use App\Actions\Quotes;
use App\Enums\BillingCycle;
use App\Enums\CouponType;
use App\Enums\LeadStatus;
use App\Enums\QuoteStatus;
use App\Enums\ServiceType;
use App\Filament\Resources\Coupons\Pages\ManageCoupons;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Leads, Quotes and Coupons lists → pick some → their mass actions. */
class SalesBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['gaiacp.tax.enabled' => false]);
        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::query()->create($attributes + ['first_name' => 'Maria', 'email' => 'maria@example.gr']);
    }

    private function quote(): Quote
    {
        return app(Quotes::class)->create([
            'lead_id' => $this->lead()->id,
            'items' => [['type' => ServiceType::Other->value, 'label' => 'Website care', 'billing_cycle' => BillingCycle::Annually->value, 'price_cents' => 10000]],
        ]);
    }

    public function test_leads_are_assigned_marked_lost_reopened_and_deleted(): void
    {
        $open = $this->lead();
        $lost = $this->lead(['status' => LeadStatus::Lost]);
        $won = $this->lead(['client_id' => Client::factory()->create()->id, 'status' => LeadStatus::Won]);

        Livewire::test(ListLeads::class)->set('activeTab', 'all')
            ->callTableBulkAction('assign', [$open, $lost], ['assigned_to' => $this->admin->id])
            ->assertNotified('1 lead assigned');
        $this->assertSame($this->admin->id, $open->fresh()->assigned_to);

        Livewire::test(ListLeads::class)->set('activeTab', 'all')
            ->callTableBulkAction('lost', [$open, $lost], ['reason' => 'No answer'])
            ->assertNotified('1 lead marked as lost');
        $this->assertSame(LeadStatus::Lost, $open->fresh()->status);

        Livewire::test(ListLeads::class)->set('activeTab', 'all')
            ->callTableBulkAction('reopen', [$open, $lost, $won])
            ->assertNotified('2 leads reopened');
        $this->assertTrue($lost->fresh()->status->isOpen());

        Livewire::test(ListLeads::class)->set('activeTab', 'all')
            ->callTableBulkAction('delete', [$open, $won])
            ->assertNotified('1 lead deleted');
        $this->assertNull($open->fresh());
        $this->assertNotNull($won->fresh());
    }

    public function test_quotes_are_sent_declined_and_deleted(): void
    {
        $draft = $this->quote();
        $accepted = $this->quote();
        $accepted->update(['status' => QuoteStatus::Accepted]);

        Livewire::test(ListQuotes::class)->set('activeTab', 'all')
            ->callTableBulkAction('send', [$draft, $accepted])
            ->assertNotified('1 quote sent');
        $this->assertSame(QuoteStatus::Sent, $draft->fresh()->status);

        Livewire::test(ListQuotes::class)->set('activeTab', 'all')
            ->callTableBulkAction('decline', [$draft, $accepted], ['reason' => 'Too expensive'])
            ->assertNotified('1 quote marked as declined');
        $this->assertSame(QuoteStatus::Declined, $draft->fresh()->status);

        Livewire::test(ListQuotes::class)->set('activeTab', 'all')
            ->callTableBulkAction('delete', [$draft, $accepted])
            ->assertNotified('1 quote deleted');
        $this->assertNull($draft->fresh());
        $this->assertNotNull($accepted->fresh());
    }

    public function test_coupons_are_switched_and_the_unused_ones_deleted(): void
    {
        $unused = Coupon::create(['code' => 'welcome', 'type' => CouponType::Percent, 'value' => 10]);
        $used = Coupon::create(['code' => 'summer', 'type' => CouponType::Percent, 'value' => 10]);
        $order = app(PlaceOrder::class)->handle(Client::factory()->create(), [
            ['type' => 'other', 'label' => 'Setup', 'price_cents' => 5000, 'billing_cycle' => BillingCycle::Annually],
        ]);
        $order->forceFill(['coupon_id' => $used->id])->save();

        Livewire::test(ManageCoupons::class)
            ->callTableBulkAction('deactivate', [$unused, $used])
            ->assertNotified('2 coupons deactivated');
        $this->assertFalse($used->fresh()->active);

        Livewire::test(ManageCoupons::class)
            ->callTableBulkAction('activate', [$unused])
            ->assertNotified('1 coupon activated');
        $this->assertTrue($unused->fresh()->active);

        Livewire::test(ManageCoupons::class)
            ->callTableBulkAction('deleteMany', [$unused, $used])
            ->assertNotified('1 coupon deleted');
        $this->assertNull($unused->fresh());
        $this->assertNotNull($used->fresh());
    }
}
