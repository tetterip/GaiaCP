<?php

namespace Plugins\Aade;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Plugins\PluginManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

class AadeSettings extends SettingsFormPage
{
    public const TOKEN_CONSOLE = 'https://www1.aade.gr/sgsisapps/tokenservices/protected/displayConsole.htm';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 76;

    protected static ?string $title = 'ΑΑΔΕ';

    protected static ?string $slug = 'aade';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Special access codes for filling in a client\'s name, Δ.Ο.Υ., profession and address from their ΑΦΜ.');
    }

    /** Hidden while the ΑΑΔΕ plugin is disabled (Settings → Plugins). */
    public static function canAccess(): bool
    {
        return app(PluginManager::class)->enabled('aade') && parent::canAccess();
    }

    public static function keys(): array
    {
        return (new AadePlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Registry lookup by ΑΦΜ'))
                    ->description(__('Used by the lookup button next to the ΑΦΜ on the client form (ΑΑΔΕ service "Αναζήτηση Βασικών Στοιχείων Μητρώου Επιχειρήσεων").'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('aade.enabled')
                            ->label('Look up clients in ΑΑΔΕ')
                            ->helperText(__('Off: no lookup button next to the ΑΦΜ on the client form.'))
                            ->live()
                            ->columnSpanFull(),
                        Text::make(new HtmlString(__(
                            'Log in to the <a href=":url" target="_blank" rel="noopener" class="underline">ΑΑΔΕ special codes console</a> '
                            .'with your TAXISnet login and press <strong>Δημιουργία Ειδικού Κωδικού</strong> for the application '
                            .'<em>Αναζήτηση Βασικών Στοιχείων Μητρώου Επιχειρήσεων</em>. Enter the username and password it gives you here; '
                            .'they are not your TAXISnet login.',
                            ['url' => self::TOKEN_CONSOLE],
                        )))->columnSpanFull()->visible(fn (Get $get) => (bool) $get('aade.enabled')),
                        TextInput::make('aade.username')
                            ->label('Username (special code)')
                            ->required(fn (Get $get) => (bool) $get('aade.enabled'))
                            ->visible(fn (Get $get) => (bool) $get('aade.enabled'))
                            ->autocomplete('off')
                            ->maxLength(255),
                        TextInput::make('aade.password')
                            ->label('Password (special code)')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.aade.password')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->required(fn (Get $get) => (bool) $get('aade.enabled') && blank(config('gaiacp.aade.password')))
                            ->visible(fn (Get $get) => (bool) $get('aade.enabled'))
                            ->maxLength(255),
                        TextInput::make('aade.afm_called_by')
                            ->label('Lookups made for ΑΦΜ (optional)')
                            ->visible(fn (Get $get) => (bool) $get('aade.enabled'))
                            ->helperText(__('Only when you look up on behalf of another business; blank uses the ΑΦΜ the codes belong to.'))
                            ->rule(fn () => function (string $attribute, $value, $fail) {
                                if (filled($value) && ! Afm::isValid(Afm::clean($value))) {
                                    $fail(__('This is not a valid ΑΦΜ.'));
                                }
                            })
                            ->maxLength(20),
                        Actions::make([
                            Action::make('testLookup')
                                ->label('Test lookup')
                                ->icon(Heroicon::OutlinedMagnifyingGlass)
                                ->color('gray')
                                ->modalDescription(__('Looks up an ΑΦΜ with the saved codes. Save the page first if you changed them.'))
                                ->schema([
                                    TextInput::make('afm')
                                        ->label('ΑΦΜ')
                                        ->required()
                                        ->default(fn () => config('gaiacp.company.vat_number')),
                                ])
                                ->modalSubmitActionLabel(__('Look up'))
                                ->action(fn (array $data) => $this->testLookup($data['afm'])),
                        ])->key('aadeActions')->columnSpanFull()->visible(fn (Get $get) => (bool) $get('aade.enabled')),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $values['aade.enabled'] = (bool) $values['aade.enabled'];

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['aade.enabled'] = (bool) $values['aade.enabled'];
        $values['aade.afm_called_by'] = filled($values['aade.afm_called_by']) ? Afm::clean($values['aade.afm_called_by']) : null;

        return $values;
    }

    public function testLookup(string $afm): void
    {
        try {
            $result = AadeRegistry::lookup($afm);
        } catch (AadeException $e) {
            Notification::make()->danger()->title(__('The lookup failed'))->body($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title(__('ΑΑΔΕ answered'))
            ->body(collect([$result['name'], $result['tax_office'] ? 'Δ.Ο.Υ. '.$result['tax_office'] : null, $result['status']])->filter()->implode(' · '))
            ->send();
    }
}
