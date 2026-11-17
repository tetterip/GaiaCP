<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Clusters\Reports\Pages\AnnualIncome;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Filament\Clusters\Settings\Pages\MailSettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\KeyboardShortcuts;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EmailTemplates;
use App\Filament\Pages\Help;
use App\Filament\Pages\Plugins;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\BillingOverview;
use App\Filament\Widgets\UpcomingRenewals;
use App\Models\Client;
use App\Models\User;
use App\Plugins\PluginManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Plugins\Aade\AadeSettings;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<AdminArea>  $areas */
    private function actingAsAdminWith(array $areas): User
    {
        User::factory()->create(); // a super admin exists, so the next one is not one by default
        $this->actingAs($admin = User::factory()->limitedTo($areas)->create());

        return $admin;
    }

    /** @return list<class-string> every page, resource and cluster in the panel, plugin pages included */
    private function components(): array
    {
        return array_values(array_unique([...Filament::getResources(), ...Filament::getPages()]));
    }

    public function test_the_first_admin_is_a_super_admin_and_later_ones_are_not(): void
    {
        $first = User::create(['name' => 'First', 'email' => 'first@example.com', 'password' => 'password']);
        $second = User::create(['name' => 'Second', 'email' => 'second@example.com', 'password' => 'password']);

        $this->assertTrue($first->fresh()->super_admin);
        $this->assertFalse($second->fresh()->super_admin);
    }

    public function test_an_admin_without_areas_sees_only_the_dashboard_and_help(): void
    {
        $this->actingAsAdminWith([]);

        $open = array_values(array_filter($this->components(), fn (string $component) => $component::canAccess()));
        $this->assertEqualsCanonicalizing([Dashboard::class, Help::class], $open, 'A page or resource without LimitedToAdminArea is open to every admin');

        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee(__('Open balance'))->assertDontSee(__('Orders and income'));
        $this->assertFalse(BillingOverview::canView());
        $this->assertFalse(UpcomingRenewals::canView());
        $this->get(ClientResource::getUrl())->assertForbidden();
        $this->get(SettingsCluster::getUrl())->assertForbidden();
        $this->get(ReportsCluster::getUrl())->assertForbidden();
        $this->assertSame(['Go to' => ['g d' => [__('Dashboard'), Dashboard::getUrl()]]], KeyboardShortcuts::sequences());
    }

    public function test_each_area_opens_its_own_pages_only(): void
    {
        $expected = [
            AdminArea::Clients->value => [ClientResource::class, OrderResource::class],
            AdminArea::Billing->value => [InvoiceResource::class],
            AdminArea::Reports->value => [AnnualIncome::class, ReportsCluster::class],
            AdminArea::BusinessSettings->value => [CompanySettings::class, AadeSettings::class, SettingsCluster::class],
            AdminArea::EmailSettings->value => [MailSettings::class, EmailTemplates::class, SettingsCluster::class],
        ];
        $super = User::factory()->create();

        foreach (AdminArea::cases() as $area) {
            $admin = User::factory()->limitedTo([$area])->create();
            $this->actingAs($admin);

            foreach ($expected[$area->value] ?? [] as $component) {
                $this->assertTrue($component::canAccess(), "{$area->value} should open {$component}");
            }

            // Super admin only, whatever is ticked
            foreach ([UserResource::class, ServerResource::class, Plugins::class] as $component) {
                $this->assertFalse($component::canAccess(), "{$area->value} must not open {$component}");
            }
        }

        // Reports → Ask in plain words shows only with an AI service set up
        app(PluginManager::class)->enable(app(PluginManager::class)->find('claude'));
        config(['gaiacp.claude.api_key' => 'sk-ant-test']);

        $this->actingAs($super);
        $this->assertTrue(collect($this->components())->every(fn (string $component) => $component::canAccess()));
    }

    public function test_pages_outside_the_areas_are_hidden_and_refused(): void
    {
        $this->actingAsAdminWith([AdminArea::Clients, AdminArea::EmailSettings]);
        $client = Client::factory()->create();

        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertOk();
        $this->get(ServiceResource::getUrl())->assertForbidden();
        $this->get(InvoiceResource::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl())->assertForbidden();
        $this->get(CompanySettings::getUrl())->assertForbidden();

        $this->get(SettingsCluster::getUrl())->assertOk()
            ->assertSee(MailSettings::getUrl())
            ->assertDontSee(CompanySettings::getUrl())
            ->assertDontSee(UserResource::getUrl());

        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee(ClientResource::getUrl())
            ->assertDontSee(InvoiceResource::getUrl())
            ->assertDontSee(ReportsCluster::getUrl());
    }

    public function test_a_super_admin_gives_an_admin_some_areas(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Helper',
                'email' => 'helper@example.com',
                'password' => 'secret-password',
                'super_admin' => false,
                'admin_areas' => [AdminArea::Clients->value, AdminArea::Billing->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $helper = User::where('email', 'helper@example.com')->sole();
        $this->assertFalse($helper->super_admin);
        $this->assertTrue($helper->canUseArea(AdminArea::Billing));
        $this->assertFalse($helper->canUseArea(AdminArea::Services));

        Livewire::test(EditUser::class, ['record' => $helper->getRouteKey()])
            ->fillForm(['super_admin' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($helper->fresh()->super_admin);
        $this->assertNull($helper->fresh()->admin_areas);
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.access_changed']);
    }

    public function test_nobody_changes_their_own_access(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test(EditUser::class, ['record' => $me->getRouteKey()])
            ->assertFormFieldIsDisabled('super_admin')
            ->fillForm(['super_admin' => false])
            ->call('save');
        $this->assertTrue($me->fresh()->super_admin);

        // An admin who isn't a super admin can't open Admin users at all.
        $limited = User::factory()->limitedTo(AdminArea::cases())->create();
        $this->actingAs($limited);
        $this->get(UserResource::getUrl('edit', ['record' => $limited]))->assertForbidden();
        Livewire::test(EditUser::class, ['record' => $limited->getRouteKey()])->assertForbidden();
    }

    public function test_search_only_finds_what_the_admin_can_open(): void
    {
        Client::factory()->create(['first_name' => 'Searchable', 'last_name' => 'Person']);

        $this->actingAsAdminWith([AdminArea::Services]);
        $this->assertNull(collect(Filament::getGlobalSearchProvider()->getResults('Searchable')?->getCategories())->first());

        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients])->create());
        $this->assertNotEmpty(Filament::getGlobalSearchProvider()->getResults('Searchable')->getCategories());
    }
}
