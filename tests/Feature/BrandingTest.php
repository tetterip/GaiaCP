<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Clusters\Settings\Pages\CompanySettings;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ResetAdminPassword;
use App\Support\Branding;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['gaiacp.company.name' => 'Acme Hosting']);
    }

    private function logo(string $file = 'branding/logo.png'): string
    {
        Storage::disk('public')->put($file, UploadedFile::fake()->image('logo.png', 400, 100)->getContent());

        return $file;
    }

    public function test_the_logo_is_uploaded_on_the_company_page_and_replacing_it_deletes_the_old_file(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CompanySettings::class)
            ->fillForm(['company' => ['logo' => UploadedFile::fake()->image('logo.png', 400, 100)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $first = config('gaiacp.company.logo');
        $this->assertStringStartsWith('branding/', $first);
        Storage::disk('public')->assertExists($first);
        $this->assertSame($first, Setting::query()->find('company.logo')->value);

        Livewire::test(CompanySettings::class)
            ->fillForm(['company' => ['logo' => [UploadedFile::fake()->image('new.png', 400, 100)]]]) // in place of the old one
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame($first, config('gaiacp.company.logo'));
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists(config('gaiacp.company.logo'));
    }

    public function test_the_files_are_served_without_storage_link_and_their_url_changes_with_each_upload(): void
    {
        Settings::save(['company.logo' => $this->logo()]);

        $url = Branding::url('logo');
        $this->assertStringContainsString('/branding/logo?v=', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

        Settings::save(['company.logo' => $this->logo('branding/other.png')]);
        $this->assertNotSame($url, Branding::url('logo'));

        // Nothing uploaded, a missing file or an unknown asset: not found
        $this->assertNull(Branding::url('favicon'));
        $this->get('/branding/favicon')->assertNotFound();
        $this->get('/branding/secret')->assertNotFound();
        Settings::save(['company.logo' => 'branding/gone.png']);
        $this->assertNull(Branding::url('logo'));
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_the_client_area_shows_the_logo_and_tab_icon_and_the_admin_panel_stays_gaiacp(): void
    {
        Settings::save([
            'company.logo' => $this->logo(),
            'company.logo_dark' => $this->logo('branding/dark.png'),
            'company.favicon' => $this->logo('branding/icon.png'),
        ]);

        $this->get('/login')->assertOk()
            ->assertSee(Branding::url('logo'), false)
            ->assertSee(Branding::url('logo-dark'), false)
            ->assertSee('<link rel="icon" href="'.e(Branding::url('favicon')).'"', false);

        $this->get('/admin/login')->assertOk()->assertSee('GaiaCP')->assertDontSee('/branding/');
        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertOk()->assertDontSee('/branding/');
    }

    public function test_without_a_logo_the_client_area_shows_the_name(): void
    {
        $this->get('/login')->assertOk()->assertSee('Acme Hosting')->assertDontSee('/branding/');
    }

    public function test_the_mail_logo_becomes_the_company_logo_on_update(): void
    {
        $migration = require database_path('migrations/2026_10_11_000040_move_mail_logo_to_company_logo.php');
        DB::table('settings')->insert(['key' => 'mail.logo', 'value' => json_encode('mail/logo.png')]);

        $migration->up();

        $this->assertSame('mail/logo.png', Setting::query()->find('company.logo')->value);
        $this->assertNull(Setting::query()->find('mail.logo'));

        // A company logo already chosen is kept
        DB::table('settings')->insert(['key' => 'mail.logo', 'value' => json_encode('mail/older.png')]);
        $migration->up();
        $this->assertSame('mail/logo.png', Setting::query()->find('company.logo')->value);
    }

    public function test_the_password_reset_email_has_the_company_logo_for_clients_and_gaiacp_for_admins(): void
    {
        config(['gaiacp.company.logo' => $this->logo()]);
        $client = Client::factory()->create(['email' => 'maria@example.gr']);
        $clientUser = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $admin = User::factory()->create();

        $reset = new ResetAdminPassword('token');
        $reset->url = 'https://example.test/reset';

        Filament::setCurrentPanel('client');
        $html = (string) $reset->toMail($clientUser)->render();
        $this->assertStringContainsString('alt="Acme Hosting"', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('https://example.test/reset', $html);
        $this->assertStringNotContainsString('alt="GaiaCP"', $html);

        Filament::setCurrentPanel('admin');
        $html = (string) $reset->toMail($admin)->render();
        $this->assertStringContainsString('alt="GaiaCP"', $html);
        $this->assertStringNotContainsString('alt="Acme Hosting"', $html);
    }
}
