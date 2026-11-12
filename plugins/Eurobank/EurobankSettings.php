<?php

namespace Plugins\Eurobank;

use App\Filament\Clusters\Settings\PaymentMethodPage;
use App\Filament\Clusters\Settings\PluginSwitch;
use App\Filament\Clusters\Settings\SettingsFormPage;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The Eurobank (Cardlink) merchant ID, shared secret and mode. Super admins only: the secret signs payments. */
class EurobankSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 25;

    protected static ?string $title = 'Eurobank';

    protected static ?string $slug = 'eurobank';

    public static function settingsDescription(): string
    {
        return __('Card payments through Eurobank (Cardlink): the merchant ID and shared secret the bank gave you.');
    }

    public static function pluginId(): string
    {
        return 'eurobank';
    }

    public static function keys(): array
    {
        return (new EurobankPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Mode'))
                    ->description(__('Clients see "Pay by card (Eurobank)" on their unpaid payment notices in euro once the merchant ID and shared secret are filled in. Pressing it opens the bank\'s own page; GaiaCP records the payment only when the bank\'s answer is signed with the shared secret and names the amount it asked for. The client\'s email, address, city, postcode and country must be filled in: the bank needs them for 3-D Secure.'))
                    ->schema([
                        ToggleButtons::make('eurobank.mode')
                            ->hiddenLabel()
                            ->options(['test' => __('Test'), 'live' => __('Live')])
                            ->colors(['test' => 'warning', 'live' => 'success'])
                            ->helperText(__('Test uses the bank\'s test environment and no real money, but records the payments here like real ones: try it on a test client. The bank gives separate test details.'))
                            ->default('test')
                            ->required()
                            ->inline(),
                    ]),
                Section::make(__('Merchant account'))
                    ->description(__('From the bank\'s set-up email for Cardlink.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('eurobank.merchant_id')
                            ->label('Merchant ID')
                            ->maxLength(50),
                        TextInput::make('eurobank.shared_secret')
                            ->label('Shared secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.eurobank.shared_secret')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->maxLength(255),
                    ]),
                Section::make(__('Addresses for the bank'))
                    ->description(__('GaiaCP sends these with every payment. If the bank asks which addresses it sends clients back to, give it these. They must not be behind a login, a maintenance page or a firewall challenge.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('success_url')
                            ->label('Success URL')
                            ->formatStateUsing(fn () => EurobankPayments::answerUrl('success'))
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false),
                        TextInput::make('failure_url')
                            ->label('Failure URL')
                            ->formatStateUsing(fn () => EurobankPayments::answerUrl('failure'))
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
