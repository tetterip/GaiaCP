<?php

namespace Tests\Feature;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\Pages\LanguageSettings;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Support\Locales;
use App\Support\Translations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Languages: languages found in lang/ and uploaded ones, switched on there, and their missing texts. */
class LanguageSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** Files this test writes into storage/app/private/lang. */
    private const WRITTEN = ['da.json', 'plugins/Telegram/da.json', 'plugins/Telegram/el.json'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['gaiacp.locales' => null]);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        foreach (self::WRITTEN as $file) {
            File::delete(Translations::overridePath($file));
        }
        Translations::flush();
    }

    private function telegram()
    {
        $plugins = app(PluginManager::class);
        $plugins->enable($telegram = $plugins->find('telegram'));

        return $telegram;
    }

    public function test_the_languages_come_from_lang_and_uploads_and_are_switched_on_in_settings(): void
    {
        $shipped = Locales::available();
        $this->assertSame(['en' => 'English', 'de' => 'Deutsch', 'el' => 'Ελληνικά'], array_slice($shipped, 0, 3));
        $this->assertSame(['en', 'de', 'el', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr'], array_keys($shipped));
        $this->assertSame('Português (Brasil)', $shipped['pt_BR']);

        File::ensureDirectoryExists(Translations::overridePath());
        File::put(Translations::overridePath('da.json'), json_encode(['Clients' => 'Kunden']));
        Translations::flush();
        $this->assertSame(['en', 'da', 'de', 'el'], array_slice(array_keys(Locales::available()), 0, 4));

        $this->actingAs(User::factory()->create());
        Livewire::test(LanguageSettings::class)
            ->assertFormSet(['languages' => ['enabled' => array_keys(Locales::available()), 'default' => 'en']])
            ->assertSee('Dansk (da)')
            ->assertSee('Deutsch (de) · 100% · '.__('machine translated'))
            ->fillForm(['languages' => ['enabled' => ['en', 'el'], 'default' => 'el']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['el' => 'Ελληνικά', 'en' => 'English'], Locales::available());
        $this->assertSame('el', Locales::default());
        $this->assertSame('el', Locales::normalize('da')); // switched off: the default instead

        Livewire::test(LanguageSettings::class)
            ->fillForm(['languages' => ['enabled' => ['en'], 'default' => 'el']])
            ->call('save')
            ->assertHasFormErrors(['languages.default']);
    }

    public function test_uploaded_translations_go_to_storage_and_win_over_the_shipped_ones(): void
    {
        $telegram = $this->telegram();
        $this->actingAs(User::factory()->create(['super_admin' => true]));

        $missing = Translations::missing('da');
        $this->assertSame($missing['total'], $missing['missing']); // nothing in Danish yet
        $this->assertSame(count(Translations::keys()['plugins']['Telegram']), count($missing['plugins']['Telegram']['missing']));
        $this->assertArrayHasKey('Clients', Translations::export('da'));
        $this->assertSame('Clients', Translations::export('da')['Clients']);

        $file = UploadedFile::fake()->createWithContent('da.json', json_encode([
            'Clients' => 'Kunden',
            'Find chat ID' => '<b>Chat-ID</b> finden', // a Telegram text: to the plugin's file, without HTML
            'Payments' => 'Payments', // left in English
            'Not a GaiaCP text' => 'Nein',
        ]));
        Livewire::test(LanguageSettings::class)
            ->callAction('upload', data: ['locale' => 'da', 'file' => $file])
            ->assertNotified(trans_choice('{0} No texts saved for :language|{1} 1 text saved for :language|[2,*] :count texts saved for :language', 2, ['language' => 'Dansk']));

        $this->assertSame(['Clients' => 'Kunden'], json_decode(File::get(Translations::overridePath('da.json')), true));
        $this->assertSame(['Find chat ID' => 'Chat-ID finden'], json_decode(File::get(Translations::overridePath('plugins/Telegram/da.json')), true));
        $this->assertArrayHasKey('da', Translations::found());
        $this->assertSame('Kunden', __('Clients', locale: 'da'));
        $this->assertSame('Chat-ID finden', __('Find chat ID', locale: 'da'));
        $this->assertSame('Payments', __('Payments', locale: 'da')); // English until translated
        $this->assertSame(Translations::missing('da')['missing'], $missing['missing'] - 2);
        $this->assertGreaterThan(0, Translations::missingForPlugin($telegram)['Dansk']);

        // A plugin's shipped Greek, overridden by an uploaded one
        Translations::import('el', ['Find chat ID' => 'Βρες το chat']);
        $this->assertSame('Βρες το chat', __('Find chat ID', locale: 'el'));
    }

    public function test_only_super_admins_download_and_upload_and_the_download_lists_whats_missing(): void
    {
        $super = User::factory()->create(['super_admin' => true]);
        $this->actingAs(User::factory()->limitedTo([AdminArea::BusinessSettings])->create()); // not a super admin
        Livewire::test(LanguageSettings::class)->assertActionHidden('download')->assertActionHidden('upload');

        $this->actingAs($super);
        Livewire::test(LanguageSettings::class)
            ->callAction('download', data: ['locale' => 'da'])
            ->assertFileDownloaded('gaiacp-missing-da.json');

        Livewire::test(LanguageSettings::class)
            ->callAction('download', data: ['locale' => 'el']) // complete
            ->assertNotified(__(':language has no missing texts', ['language' => 'Ελληνικά']));
    }

    public function test_the_command_lists_one_plugins_missing_texts(): void
    {
        $this->artisan('gaiacp:translations', ['locale' => 'el', '--plugin' => 'Telegram'])
            ->expectsOutputToContain('0 not in plugins/Telegram/lang/el.json')
            ->assertSuccessful();
        $this->artisan('gaiacp:translations', ['locale' => 'el', '--plugin' => 'Nope'])->assertFailed();
    }
}
