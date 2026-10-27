<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Support\Locales;
use App\Support\Translations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Intl\Languages;
use UnitEnum;

/**
 * Settings → Languages: which of the languages found (App\Support\Translations) admins and clients can pick, the
 * default one, and how complete each is, core and plugin by plugin. Super admins download a language's missing texts
 * and upload the translated file (or a new language), kept in storage so updates never replace it.
 */
class LanguageSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'General';

    protected static ?int $navigationSort = 11;

    protected static ?string $title = 'Languages';

    protected static ?string $slug = 'languages';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('The languages admins and clients can pick, the default one, and the texts each one still misses.');
    }

    public static function keys(): array
    {
        return ['languages.enabled', 'languages.default'];
    }

    private static function managesFiles(): bool
    {
        return (bool) auth()->user()?->super_admin;
    }

    /** @return array<string, string> code => "Ελληνικά (el) · 98%" */
    private static function options(): array
    {
        return collect(Translations::found())
            ->mapWithKeys(fn (string $name, string $code) => [$code => $code === 'en'
                ? __(':language (:code) · built in', ['language' => $name, 'code' => $code])
                : __(':language (:code) · :percent%', ['language' => $name, 'code' => $code, 'percent' => Translations::percent($code)])
                    .(in_array($code, Translations::MACHINE_TRANSLATED, true) ? ' · '.__('machine translated') : '')])
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Languages'))
                    ->schema([
                        CheckboxList::make('languages.enabled')
                            ->label('Languages offered')
                            ->options(fn () => self::options())
                            ->required()
                            ->live()
                            ->helperText(__('Admins pick theirs in Profile, clients in their profile and you on each client. A language switched off keeps their choice: they see the default one until it is on again. Texts not translated yet show in English.')),
                        Select::make('languages.default')
                            ->label('Default language')
                            ->options(fn (Get $get) => array_intersect_key(Translations::found(), array_flip((array) $get('languages.enabled'))))
                            ->selectablePlaceholder(false)
                            ->required()
                            ->in(fn (Get $get) => (array) $get('languages.enabled'))
                            ->helperText(__('For admins and clients without a language of their own, the login pages and the daily emails to admins.')),
                    ]),
                Section::make(__('Missing texts'))
                    ->description(__('A plugin update can bring new texts: they show in English until translated. Download a language\'s missing texts, translate the values and upload the file; uploaded translations are kept when GaiaCP or a plugin updates.'))
                    ->schema([
                        View::make('filament.settings.languages')
                            ->viewData(fn () => ['languages' => collect(Translations::found())->except('en')
                                ->map(fn (string $name, string $code) => ['name' => $name, 'percent' => Translations::percent($code), 'missing' => Translations::missing($code)])]),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $found = array_keys(Translations::found());
        $values['languages.enabled'] = array_values(array_intersect(array_keys(Locales::available()), $found)) ?: $found;
        $values['languages.default'] = Locales::default();

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $found = array_keys(Translations::found());
        $enabled = array_values(array_intersect($found, (array) $values['languages.enabled']));
        $default = in_array($values['languages.default'], $enabled, true) ? $values['languages.default'] : ($enabled[0] ?? 'en');

        return ['languages.enabled' => $enabled ?: ['en'], 'languages.default' => $default];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download missing texts')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => self::managesFiles())
                ->modalHeading(__('Download missing texts'))
                ->modalDescription(__('A file with every text the language does not translate yet, the English as each value. Replace the values and upload it back.'))
                ->schema([
                    Select::make('locale')
                        ->label('Language')
                        ->options(fn () => self::newLanguageOptions())
                        ->searchable()
                        ->required(),
                ])
                ->modalSubmitActionLabel(__('Download'))
                ->action(function (array $data): ?StreamedResponse {
                    abort_unless(self::managesFiles(), 403);
                    $locale = (string) $data['locale'];
                    abort_unless(Translations::validCode($locale) && $locale !== 'en', 422);

                    $lines = Translations::export($locale);
                    if ($lines === []) {
                        Notification::make()->success()->title(__(':language has no missing texts', ['language' => Translations::name($locale)]))->send();

                        return null;
                    }

                    return response()->streamDownload(
                        function () use ($lines) {
                            echo json_encode($lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                        },
                        "gaiacp-missing-{$locale}.json",
                        ['Content-Type' => 'application/json'],
                    );
                }),
            Action::make('upload')
                ->label('Upload translations')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn () => self::managesFiles())
                ->modalHeading(__('Upload translations'))
                ->modalDescription(__('A .json file of English text and translation, like the one Download missing texts gives. A language not on the list yet is added (switch it on below). Texts left in English are skipped.'))
                ->schema([
                    Select::make('locale')
                        ->label('Language')
                        ->options(fn () => self::newLanguageOptions())
                        ->searchable()
                        ->required(),
                    FileUpload::make('file')
                        ->label('Translations file')
                        ->required()
                        ->disk('local')
                        ->directory('translation-uploads')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/json', 'text/plain', 'text/json'])
                        ->maxSize(2048),
                ])
                ->modalSubmitActionLabel(__('Upload'))
                ->action(function (array $data, Action $action) {
                    abort_unless(self::managesFiles(), 403);
                    $locale = (string) $data['locale'];
                    $lines = json_decode((string) Storage::disk('local')->get($data['file']), true);
                    Storage::disk('local')->delete($data['file']);

                    if (! Translations::validCode($locale) || $locale === 'en' || ! is_array($lines)) {
                        Notification::make()->danger()->title(__('Not a translations file'))->body(__('Upload a .json file of English text and translation.'))->send();
                        $action->halt();

                        return;
                    }

                    $result = Translations::import($locale, $lines);
                    $this->mount();
                    Notification::make()->success()
                        ->title(trans_choice('{0} No texts saved for :language|{1} 1 text saved for :language|[2,*] :count texts saved for :language', $result['saved'], ['language' => Translations::name($locale)]))
                        ->body($result['skipped'] > 0 ? trans_choice('{1} 1 skipped: left in English or not a GaiaCP text.|[2,*] :count skipped: left in English or not GaiaCP texts.', $result['skipped']) : null)
                        ->send();
                }),
        ];
    }

    /** @return array<string, string> every language GaiaCP could have, the ones found first */
    private static function newLanguageOptions(): array
    {
        $found = collect(Translations::found())->except('en')->mapWithKeys(fn (string $name, string $code) => [$code => "{$name} ({$code})"]);
        $others = collect(Languages::getNames(app()->getLocale()))
            ->filter(fn (string $name, string $code) => $code !== 'en' && ! $found->has($code) && Translations::validCode($code))
            ->map(fn (string $name, string $code) => "{$name} ({$code})");

        return $found->merge($others)->all();
    }
}
