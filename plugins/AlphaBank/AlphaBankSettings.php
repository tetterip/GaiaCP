<?php

namespace Plugins\AlphaBank;

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

/** The Alpha Bank e-Commerce merchant ID, shared secret and mode. Super admins only: the secret signs payments. */
class AlphaBankSettings extends SettingsFormPage
{
    use PaymentMethodPage;
    use PluginSwitch;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 24;

    protected static ?string $title = 'Alpha Bank';

    protected static ?string $slug = 'alphabank';

    public static function settingsDescription(): string
    {
        return __('Card payments through Alpha Bank e-Commerce: the merchant ID and shared secret the bank gave you.');
    }

    public static function pluginId(): string
    {
        return 'alphabank';
    }

    public static function keys(): array
    {
        return (new AlphaBankPlugin)->settingKeys();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Mode'))
                    ->description(__('Clients see "Pay by card (Alpha Bank)" on their unpaid payment notices in euro once the merchant ID and shared secret are filled in. Pressing it opens the bank\'s own page; GaiaCP records the payment only when the bank\'s answer is signed with the shared secret and names the amount it asked for. The client\'s email, address, city, postcode and country must be filled in: the bank needs them for 3-D Secure.'))
                    ->schema([
                        ToggleButtons::make('alphabank.mode')
                            ->hiddenLabel()
                            ->options(['test' => __('Test'), 'live' => __('Live')])
                            ->colors(['test' => 'warning', 'live' => 'success'])
                            ->helperText(__('Test uses the bank\'s test environment and no real money, but records the payments here like real ones: try it on a test client. The bank gives separate test details.'))
                            ->default('test')
                            ->required()
                            ->inline(),
                    ]),
                Section::make(__('Merchant account'))
                    ->description(__('From the bank\'s set-up email for Alpha e-Commerce (Cardlink). Merchants who signed with Alpha Bank after Nexi took over its card payments (mid-2022) get Nexi XPay details instead, which do not work here.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('alphabank.merchant_id')
                            ->label('Merchant ID')
                            ->maxLength(50),
                        TextInput::make('alphabank.shared_secret')
                            ->label('Shared secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn () => filled(config('gaiacp.alphabank.shared_secret')) ? '••••••••' : null)
                            ->helperText(__('Stored encrypted. Leave blank to keep the saved one.'))
                            ->maxLength(255),
                    ]),
                Section::make(__('Addresses for the bank'))
                    ->description(__('GaiaCP sends these with every payment. If the bank asks which addresses it sends clients back to, give it these. They must not be behind a login, a maintenance page or a firewall challenge.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('success_url')
                            ->label('Success URL')
                            ->formatStateUsing(fn () => AlphaBankPayments::answerUrl('success'))
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false),
                        TextInput::make('failure_url')
                            ->label('Failure URL')
                            ->formatStateUsing(fn () => AlphaBankPayments::answerUrl('failure'))
                            ->readOnly()
                            ->copyable()
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
