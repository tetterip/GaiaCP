<?php

namespace Plugins\Vies;

use App\Enums\AdminArea;
use App\Filament\Clusters\Settings\SettingsFormPage;
use App\Plugins\PluginManager;
use App\Support\Region;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\Intl\Countries;
use UnitEnum;

class ViesSettings extends SettingsFormPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 23;

    protected static ?string $title = 'VIES VAT check';

    protected static ?string $slug = 'vies';

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::BusinessSettings;
    }

    public static function settingsDescription(): string
    {
        return __('Checking EU VAT numbers in VIES, and the reverse charge for EU businesses in another EU country.');
    }

    /** Hidden while the VIES plugin is disabled (Settings → Plugins). */
    public static function canAccess(): bool
    {
        return app(PluginManager::class)->enabled('vies') && parent::canAccess();
    }

    public static function keys(): array
    {
        return (new ViesPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Your business'))
                    ->description(__('The button next to a client\'s VAT number checks it in VIES, the EU\'s VAT number service. Your own VAT number is sent with each check, so VIES gives a consultation number you can keep as proof.'))
                    ->columns(2)
                    ->schema([
                        Select::make('vies.home_country')
                            ->label('Your EU country')
                            ->options(fn () => collect(Countries::getNames(app()->getLocale()))->only(array_keys(Vies::MEMBER_STATES))->all())
                            ->searchable()
                            ->placeholder(fn () => Region::country()
                                ? __('As Settings → General: :country', ['country' => Countries::getName(Region::country(), app()->getLocale())])
                                : __('Pick your country')),
                        TextInput::make('vies.requester_vat')
                            ->label('Your VAT number')
                            ->placeholder(fn () => config('gaiacp.company.vat_number') ?: null)
                            ->helperText(__('Blank uses the one under Settings → Company.'))
                            ->maxLength(20),
                        Actions::make([
                            Action::make('testCheck')
                                ->label('Check a VAT number')
                                ->icon(Heroicon::OutlinedCheckBadge)
                                ->color('gray')
                                ->modalDescription(__('Asks VIES about a VAT number. Save the page first if you changed it.'))
                                ->schema([
                                    Select::make('country')
                                        ->label('Country')
                                        ->options(fn () => collect(Countries::getNames(app()->getLocale()))->only(array_keys(Vies::MEMBER_STATES))->all())
                                        ->default(fn () => Vies::homeCountry())
                                        ->searchable()
                                        ->required(),
                                    TextInput::make('vat_number')
                                        ->label('VAT number')
                                        ->required(),
                                ])
                                ->modalSubmitActionLabel(__('Check'))
                                ->action(fn (array $data) => $this->testCheck($data['vat_number'], $data['country'])),
                        ])->key('viesActions')->columnSpanFull(),
                    ]),
                Section::make(__('Reverse charge'))
                    ->description(__('An EU business in another EU country pays no VAT on your services: it accounts for it itself. Only for clients whose VAT number VIES found valid, and only while Settings → Tax charges tax.'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('vies.reverse_charge')
                            ->label('No tax for EU businesses in another EU country')
                            ->helperText(__('Their payment notices show no tax and the note below. A notice keeps what it was created with.'))
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('vies.valid_days')
                            ->label('A valid check counts for (days)')
                            ->helperText(__('After that, check the client again or their notices charge tax again.'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->required()
                            ->visible(fn (Get $get) => (bool) $get('vies.reverse_charge')),
                        Textarea::make('vies.reverse_charge_note')
                            ->label('Note on the payment notice')
                            ->placeholder(fn () => Vies::defaultNote())
                            ->helperText(__('Blank prints the built-in text in the client\'s language.'))
                            ->rows(2)
                            ->maxLength(500)
                            ->visible(fn (Get $get) => (bool) $get('vies.reverse_charge'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function forForm(array $values): array
    {
        $values['vies.reverse_charge'] = (bool) $values['vies.reverse_charge'];

        return $values;
    }

    protected function forStorage(array $values): array
    {
        $values['vies.reverse_charge'] = (bool) $values['vies.reverse_charge'];
        $values['vies.valid_days'] = (int) ($values['vies.valid_days'] ?? 365) ?: 365;
        $values['vies.requester_vat'] = filled($values['vies.requester_vat'] ?? null)
            ? strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $values['vies.requester_vat']))
            : null;
        $values['vies.reverse_charge_note'] = filled($values['vies.reverse_charge_note'] ?? null) ? trim($values['vies.reverse_charge_note']) : null;

        return $values;
    }

    public function testCheck(string $number, string $country): void
    {
        try {
            $check = Vies::check($number, $country);
        } catch (ViesException $e) {
            Notification::make()->danger()->title(__('VIES check failed'))->body($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()
            ->{$check->valid ? 'success' : 'warning'}()
            ->title($check->valid
                ? __('Valid in VIES: :number', ['number' => $check->country_code.$check->vat_number])
                : __('Not valid in VIES: :number', ['number' => $check->country_code.$check->vat_number]))
            ->body(collect([$check->name, $check->request_identifier ? __('Consultation number :id', ['id' => $check->request_identifier]) : null])->filter()->implode(' · '))
            ->send();
    }
}
