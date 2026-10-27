<?php

namespace Tests\Feature;

use App\Filament\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\Client;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Support\EppPhone;
use App\Support\Health\Status;
use App\Support\Health\SystemHealth;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → General → Region: timezone, date format and the country of new clients, none of them assumed Greek. */
class RegionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_region_is_saved_and_used_at_once(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(GeneralSettings::class)
            ->assertFormSet(['region' => ['timezone' => 'Europe/Athens', 'date_format' => 'd/m/Y', 'country' => 'GR']])
            ->fillForm(['region' => ['timezone' => 'America/Chicago', 'date_format' => 'm/d/Y', 'country' => 'US']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('America/Chicago', config('app.timezone'));
        $this->assertSame('America/Chicago', date_default_timezone_get());
        $this->assertSame('US', Setting::query()->find('region.country')->value);

        Livewire::test(GeneralSettings::class)
            ->fillForm(['region' => ['timezone' => 'Mars/Olympus', 'date_format' => 'Y/d/m']])
            ->call('save')
            ->assertHasFormErrors(['region.timezone', 'region.date_format']);

        // A new boot reads them back; tables write dates in the chosen format
        Settings::apply();
        $service = Service::factory()->dueOn('2027-03-21')->create();
        Livewire::test(ListServices::class)->assertSee('03/21/2027')->assertDontSee('21/03/2027');
        $this->assertSame('US', Client::factory()->create(['country' => null])->country);
        $this->assertSame('CY', Client::factory()->create(['country' => 'CY'])->country);
    }

    public function test_phone_numbers_without_a_code_are_read_as_the_clients_country(): void
    {
        $this->assertSame('+44.2079460018', EppPhone::format('020 7946 0018', 'GB'));
        $this->assertSame('+39.0612345678', EppPhone::format('06 1234 5678', 'IT')); // Italy keeps its 0
        $this->assertSame('+1.2125550123', EppPhone::format('(212) 555-0123', 'US'));
        $this->assertSame('+30.6971234567', EppPhone::format('6971234567')); // Settings → General → Country (GR)
        $this->assertSame('+49.301234567', EppPhone::format('+49 30 1234567', 'GB'));

        config(['gaiacp.region.country' => null]);
        $this->assertSame('+44.2079460018', EppPhone::format('442079460018')); // no country: as written
    }

    public function test_new_installs_assume_no_region_or_tax_and_existing_ones_keep_theirs(): void
    {
        $config = require config_path('gaiacp.php');
        $this->assertSame([], $config['tax']['rules']);

        $migration = require database_path('migrations/2026_10_24_000300_pin_region_settings.php');
        $migration->up(); // no admins yet: a new install
        $this->assertSame(0, Setting::query()->count());

        User::factory()->create();
        Setting::query()->create(['key' => 'region.country', 'value' => 'CY']);
        $migration->up();

        $this->assertSame('Europe/Athens', Setting::query()->find('region.timezone')->value);
        $this->assertSame('d/m/Y', Setting::query()->find('region.date_format')->value);
        $this->assertSame('CY', Setting::query()->find('region.country')->value); // already set: kept
        $this->assertSame([['name' => 'ΦΠΑ', 'rate' => 17, 'country' => null]], Setting::query()->find('tax.rules')->value);
        $this->assertSame(4, DB::table('settings')->count());
    }

    public function test_health_accepts_any_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        Http::fake(['*' => Http::response('', 404)]);

        $check = app(SystemHealth::class)->checks()->firstWhere('name', 'Timezone');

        $this->assertSame(Status::Ok, $check->status);
        $this->assertSame('Asia/Tokyo', $check->value);
    }
}
