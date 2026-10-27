<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Filament\Clusters\Reports\Pages\DomainProfit;
use App\Filament\Clusters\Reports\Pages\IncomeForecast;
use App\Filament\Resources\Tlds\Pages\ManageTlds;
use App\Models\Service;
use App\Models\Tld;
use App\Models\User;
use App\Reports\IncomeForecast as Forecast;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** What the registrar charges per TLD: the margin on Domain pricing and domains' costs in the reports. */
class DomainCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00');
        $this->actingAs(User::factory()->create());
    }

    private function domain(string $name, int $price, array $attributes = []): Service
    {
        $service = Service::factory()->domain()->create(['domain' => $name, 'price_cents' => $price] + $attributes);
        $service->domainDetails()->updateOrCreate([], ['registration_type' => DomainRegistrationType::Register]);

        return $service;
    }

    public function test_costs_are_saved_with_the_tld_and_give_its_margin(): void
    {
        $tld = Tld::query()->create(['tld' => '.com', 'renew_1y_cents' => 1500, 'renew_2y_cents' => 2800]);

        Livewire::test(ManageTlds::class)
            ->callAction(TestAction::make('edit')->table($tld), ['costs.renew_1y_cents' => '11', 'costs.renew_2y_cents' => '30'])
            ->assertHasNoFormErrors();

        $tld->refresh();
        $this->assertSame(1100, $tld->costCents('renew', 1));
        $this->assertSame(400, $tld->marginCents('renew', 1));
        $this->assertSame(-200, $tld->marginCents('renew', 2));
        $this->assertNull($tld->marginCents('register', 1));
        $this->assertTrue($tld->sellsAtALoss());

        Livewire::test(ManageTlds::class)->assertSee('1 year: €4.00 (27%)');
    }

    public function test_domain_costs_are_expenses_in_the_forecast_and_the_domain_profit_report(): void
    {
        Tld::query()->create(['tld' => '.gr', 'renew_2y_cents' => 3000, 'costs' => ['renew_2y_cents' => 1800]]);
        Tld::query()->create(['tld' => '.com', 'renew_1y_cents' => 1500]);
        $this->domain('maria.gr', 3000, ['billing_cycle' => BillingCycle::Biennially, 'start_date' => '2025-01-20', 'next_due_date' => '2027-01-20']);
        $this->domain('maria.com', 1500, ['start_date' => '2025-11-05', 'next_due_date' => '2026-11-05']);
        $external = $this->domain('elsewhere.gr', 3000, ['billing_cycle' => BillingCycle::Biennially, 'next_due_date' => '2027-02-01']);
        $external->domainDetails->update(['registration_type' => DomainRegistrationType::External]);

        $months = (new Forecast)->monthly(12);
        $this->assertSame(1800, $months[3]['domain_costs']); // January: maria.gr's renewal
        $this->assertSame(0, $months[1]['domain_costs']); // November: .com has no cost set

        $this->get(IncomeForecast::getUrl())->assertOk()->assertSee('Domain costs')->assertSee('Profit');

        $tlds = (new Forecast)->byTld()->keyBy('tld');
        $this->assertSame(['tld' => '.gr', 'domains' => 1, 'yearly_cents' => 1500, 'yearly_cost_cents' => 900, 'without_cost' => 0], $tlds['.gr']);
        $this->assertSame(1, $tlds['.com']['without_cost']);
        $this->assertSame(2, $tlds->sum('domains')); // the tracked one is left out

        $this->get(DomainProfit::getUrl())->assertOk()->assertSee('.gr')->assertSee('€6.00')->assertSee('40%');
    }
}
