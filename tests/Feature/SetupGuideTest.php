<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\Pages\PublicAreaSettings;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SetupGuide as SetupGuidePage;
use App\Filament\Widgets\SetupGuideCard;
use App\Models\Client;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use App\Support\SetupGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → General → Setup guide and its card on the dashboard: the first steps of a new install. */
class SetupGuideTest extends TestCase
{
    use RefreshDatabase;

    private function stepStatus(string $key): ?string
    {
        return SetupGuide::steps()->firstWhere('key', $key)['status'];
    }

    public function test_steps_are_done_by_what_is_set_up(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertNull($this->stepStatus('server'));
        $this->assertNull($this->stepStatus('company'));
        $this->assertNull($this->stepStatus('region')); // the config default alone doesn't count

        Server::factory()->create();
        Settings::save(['company.name' => 'Acme', 'company.email' => 'hi@acme.test', 'company.address' => 'Main St 1', 'region.timezone' => 'UTC']);

        $this->assertSame(SetupGuide::DONE, $this->stepStatus('server'));
        $this->assertSame(SetupGuide::DONE, $this->stepStatus('company'));
        $this->assertSame(SetupGuide::DONE, $this->stepStatus('region'));
        $this->assertTrue(SetupGuide::steps()->firstWhere('key', 'server')['auto']);
    }

    public function test_the_public_area_step_is_done_once_its_page_is_saved(): void
    {
        $this->actingAs(User::factory()->create());

        $keys = array_column(SetupGuide::definitions(), 'key');
        $this->assertSame(array_search('client_area', $keys) + 1, array_search('public_area', $keys));
        $this->assertNull($this->stepStatus('public_area'));

        Livewire::test(PublicAreaSettings::class)->call('save'); // every page left off still counts: the admin decided

        $this->assertSame(SetupGuide::DONE, $this->stepStatus('public_area'));
        $this->assertSame(PublicAreaSettings::getUrl(), SetupGuide::steps()->firstWhere('key', 'public_area')['url']);
    }

    public function test_the_admin_marks_skips_and_undoes_steps(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SetupGuidePage::class)
            ->assertSee('Payment methods')->assertSee('Your first server')
            ->call('mark', 'payments', 'done')
            ->call('mark', 'server', 'skipped')
            ->assertSee('Skipped');

        $this->assertSame(SetupGuide::DONE, $this->stepStatus('payments'));
        $this->assertSame(SetupGuide::SKIPPED, $this->stepStatus('server'));

        Server::factory()->create(); // added after all: done, not skipped
        $this->assertSame(SetupGuide::DONE, $this->stepStatus('server'));

        Livewire::test(SetupGuidePage::class)->call('mark', 'payments', null);
        $this->assertNull($this->stepStatus('payments'));
        $this->assertSame(['server' => SetupGuide::SKIPPED], SetupGuide::state()['marked']);

        Livewire::test(SetupGuidePage::class)->call('mark', 'nope', 'done')->assertStatus(404);
    }

    public function test_the_dashboard_card_shows_until_everything_is_done_or_hidden(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Finish setting up GaiaCP')->assertSee(SetupGuidePage::getUrl());

        foreach (array_column(SetupGuide::definitions(), 'key') as $key) {
            SetupGuide::mark($key, SetupGuide::SKIPPED);
        }
        $this->assertTrue(SetupGuide::finished());
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('Finish setting up GaiaCP');
        $this->get(SetupGuidePage::getUrl())->assertOk()->assertSee('All set.');

        SetupGuide::mark('payments', null);
        Livewire::test(SetupGuideCard::class)->call('hide');
        $this->assertTrue(SetupGuide::state()['hidden']);
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('Finish setting up GaiaCP');

        Livewire::test(SetupGuidePage::class)->callAction('dashboard');
        $this->assertFalse(SetupGuide::state()['hidden']);
    }

    public function test_only_super_admins_see_the_guide(): void
    {
        User::factory()->create();
        $this->actingAs(User::factory()->limitedTo([AdminArea::Clients])->create());

        $this->get(SetupGuidePage::getUrl())->assertForbidden();
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('Finish setting up GaiaCP');
    }

    public function test_an_install_with_clients_starts_with_the_guide_hidden(): void
    {
        Setting::query()->whereKey(SetupGuide::SETTING)->delete();
        Client::factory()->create();

        (require database_path('migrations/2026_10_24_000600_hide_setup_guide_on_existing_installs.php'))->up();

        $this->assertTrue(SetupGuide::state()['hidden']);
    }
}
