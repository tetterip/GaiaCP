<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ActivityChart;
use App\Filament\Widgets\BillingOverview;
use App\Models\User;
use App\Support\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The admin dashboard: each admin's own columns and order (dragged on the dashboard) and hidden cards (Show / hide cards). */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_hides_cards_for_themselves_only(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)
            ->mountAction('arrangeDashboard')
            ->assertActionDataSet(function (array $state) {
                $this->assertTrue($state['cards']['NeedsAttention']);

                return [];
            })
            ->setActionData(['cards' => ['ActivityChart' => false, 'Unknown' => false]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $shown = array_merge(...DashboardLayout::shown($admin->fresh()));
        $this->assertArrayNotHasKey('ActivityChart', $shown);
        $this->assertArrayHasKey('NeedsAttention', $shown);
        // Hiding a card leaves the order as it was.
        $this->assertSame(array_keys(DashboardLayout::widgets()), array_column(DashboardLayout::rows($admin->fresh()), 'widget'));

        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Open balance')->assertDontSee('Orders and income');

        // Another admin keeps the default.
        $this->assertNull($other->fresh()->dashboard_layout);
        $this->actingAs($other)->get(Dashboard::getUrl())->assertSee('Orders and income');
    }

    public function test_cards_start_in_two_columns_most_urgent_first(): void
    {
        $admin = User::factory()->create();

        $shown = DashboardLayout::shown($admin);
        $this->assertSame(['SetupGuideCard', 'BillingOverview', 'ServerCapacity', 'NeedsAttention'], array_keys($shown[1]));
        $this->assertSame(['ActivityChart', 'UpcomingRenewals', 'SupportTickets'], array_keys($shown[2]));

        $this->actingAs($admin)->get(Dashboard::getUrl())->assertOk()
            ->assertSee('data-gx-column="2"', false)
            ->assertSee('Show / hide cards')
            ->assertDontSee('Arrange cards')
            ->assertSeeInOrder(['Open balance', 'Needs attention', 'Orders and income']);
    }

    public function test_dragging_a_card_saves_its_column_and_order(): void
    {
        $admin = User::factory()->create();
        DashboardLayout::show($admin, ['ActivityChart' => false]);
        $this->actingAs($admin);

        $shown = DashboardLayout::shown($admin);
        $this->assertArrayNotHasKey('ActivityChart', $shown[2]);

        // Needs attention dragged to the top of the second column, Open balance to the end of the first.
        $first = [...array_values(array_diff(array_keys($shown[1]), ['NeedsAttention', 'BillingOverview'])), 'BillingOverview'];
        $second = ['NeedsAttention', ...array_keys($shown[2]), 'Gone'];

        Livewire::test(Dashboard::class)->call('reorderDashboard', [1 => $first, 2 => $second]);

        $shown = DashboardLayout::shown($admin->fresh());
        $this->assertSame($first, array_keys($shown[1]));
        $this->assertSame(array_slice($second, 0, -1), array_keys($shown[2]));
        // The hidden card keeps its place and column.
        $this->assertSame(['widget' => 'ActivityChart', 'column' => 2, 'visible' => false], collect(DashboardLayout::rows($admin->fresh()))->firstWhere('widget', 'ActivityChart'));

        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('x-sortable-item="NeedsAttention"', false)
            ->assertSeeInOrder(['Open balance', 'Needs attention']);
    }

    public function test_a_new_card_joins_the_shorter_column(): void
    {
        $widgets = array_keys(DashboardLayout::widgets());
        $admin = User::factory()->create(['dashboard_layout' => collect($widgets)
            ->reject(fn (string $key) => $key === 'SupportTickets')
            ->map(fn (string $key) => ['widget' => $key, 'column' => 1, 'visible' => true])
            ->values()
            ->all()]);

        $this->assertSame(2, collect(DashboardLayout::rows($admin))->firstWhere('widget', 'SupportTickets')['column']);
    }

    public function test_back_to_the_default(): void
    {
        $admin = User::factory()->create(['dashboard_layout' => [['widget' => 'ActivityChart', 'visible' => false]]]);
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)
            ->mountAction('arrangeDashboard')
            ->callAction('resetDashboard');

        $this->assertNull($admin->fresh()->dashboard_layout);
    }

    public function test_a_card_the_admin_may_not_open_is_never_offered_or_shown(): void
    {
        User::factory()->create(); // the first admin always opens everything
        $admin = User::factory()->limitedTo([AdminArea::Clients])->create();
        $admin->forceFill(['dashboard_layout' => [['widget' => 'BillingOverview', 'visible' => true]]])->save();
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)
            ->mountAction('arrangeDashboard')
            ->assertActionDataSet(function (array $state) {
                $this->assertArrayNotHasKey('BillingOverview', $state['cards']);

                return [];
            })
            ->setActionData(['cards' => ['BillingOverview' => false]])
            ->callMountedAction();

        // A card it can't open can't be hidden or shown from this admin's form either.
        $this->assertTrue(collect(DashboardLayout::rows($admin->fresh()))->firstWhere('widget', 'BillingOverview')['visible']);
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('Open balance');
    }

    public function test_an_unknown_or_broken_layout_falls_back_to_every_card(): void
    {
        $admin = User::factory()->create(['dashboard_layout' => [['widget' => 'Gone', 'size' => 'full'], 'junk', ['widget' => 'ServerCapacity', 'size' => 'huge']]]);

        $rows = collect(DashboardLayout::rows($admin));

        $this->assertSame(['widget' => 'ServerCapacity', 'column' => 1, 'visible' => true], $rows[0]);
        $this->assertEqualsCanonicalizing(array_keys(DashboardLayout::widgets()), $rows->pluck('widget')->all());
    }

    public function test_every_card_fills_its_place(): void
    {
        $this->assertSame('full', (new ActivityChart)->getColumnSpan());
        $this->assertSame('full', (new BillingOverview)->getColumnSpan());
    }
}
